resource "aws_vpc" "lab" {
  cidr_block           = var.vpc_cidr
  enable_dns_support   = true
  enable_dns_hostnames = true
  tags                 = { Name = local.name }
}
resource "aws_internet_gateway" "lab" {
  vpc_id = aws_vpc.lab.id
  tags   = { Name = local.name }
}
resource "aws_subnet" "public" {
  count                   = 2
  vpc_id                  = aws_vpc.lab.id
  cidr_block              = cidrsubnet(var.vpc_cidr, 8, count.index)
  availability_zone       = data.aws_availability_zones.available.names[count.index]
  map_public_ip_on_launch = true
  tags = {
    Name                                  = "${local.name}-public-${count.index + 1}"
    "kubernetes.io/role/elb"              = "1"
    "kubernetes.io/cluster/${local.name}" = "owned"
  }
}
resource "aws_route_table" "public" {
  vpc_id = aws_vpc.lab.id
  tags   = { Name = "${local.name}-public" }
}
resource "aws_route" "internet" {
  route_table_id         = aws_route_table.public.id
  destination_cidr_block = "0.0.0.0/0"
  gateway_id             = aws_internet_gateway.lab.id
}
resource "aws_route_table_association" "public" {
  count          = 2
  subnet_id      = aws_subnet.public[count.index].id
  route_table_id = aws_route_table.public.id
}
# One zonal egress path: the second private AZ depends on the NAT in AZ 0.
resource "aws_eip" "nat" {
  domain = "vpc"
  tags   = { Name = "${local.name}-nat" }
}
resource "aws_nat_gateway" "lab" {
  allocation_id     = aws_eip.nat.id
  subnet_id         = aws_subnet.public[0].id
  connectivity_type = "public"
  tags              = { Name = "${local.name}-nat" }
  depends_on        = [aws_internet_gateway.lab, aws_route.internet, aws_route_table_association.public]
}
resource "aws_route" "private_nat" {
  route_table_id         = aws_route_table.database.id
  destination_cidr_block = "0.0.0.0/0"
  nat_gateway_id         = aws_nat_gateway.lab.id
}
resource "aws_security_group" "cluster" {
  name        = "${local.name}-control-plane"
  description = "Dedicated control-plane ENI group; API clients also checked by EKS CIDR allowlist"
  vpc_id      = aws_vpc.lab.id
  tags        = { Name = "${local.name}-control-plane" }
}
resource "aws_security_group" "nodes" {
  name        = "${local.name}-nodes"
  description = "No Internet ingress; custom launch template avoids attaching broad EKS default group"
  vpc_id      = aws_vpc.lab.id
  tags        = { Name = "${local.name}-nodes", "kubernetes.io/cluster/${local.name}" = "owned" }
}
resource "aws_security_group" "alb" {
  name        = "${local.name}-alb"
  description = "Public demo HTTP frontend only; no production DNS or TLS"
  vpc_id      = aws_vpc.lab.id
  tags        = { Name = "${local.name}-alb" }
}
resource "aws_vpc_security_group_ingress_rule" "alb_http" {
  for_each          = toset(var.allowed_client_cidrs)
  security_group_id = aws_security_group.alb.id
  cidr_ipv4         = each.value
  ip_protocol       = "tcp"
  from_port         = 80
  to_port           = 80
  lifecycle {
    precondition {
      condition     = each.value != "${aws_eip.nat.public_ip}/32"
      error_message = "Do not copy the NAT EIP into allowed_client_cidrs: its HTTP rule is managed separately."
    }
  }
}
# A singleton address keeps the resource graph known before EIP allocation.
resource "aws_vpc_security_group_ingress_rule" "alb_nat_http" {
  security_group_id = aws_security_group.alb.id
  cidr_ipv4         = "${aws_eip.nat.public_ip}/32"
  ip_protocol       = "tcp"
  from_port         = 80
  to_port           = 80
  description       = "Private runner smoke through the lab NAT EIP only"
  lifecycle {
    precondition {
      condition     = !contains(var.allowed_client_cidrs, "${aws_eip.nat.public_ip}/32")
      error_message = "Do not copy the NAT EIP into allowed_client_cidrs: that would create duplicate AWS ingress rules."
    }
  }
}
resource "aws_vpc_security_group_ingress_rule" "alb_https" {
  for_each          = var.enable_lab_https ? toset(var.allowed_client_cidrs) : toset([])
  security_group_id = aws_security_group.alb.id
  cidr_ipv4         = each.value
  ip_protocol       = "tcp"
  from_port         = 443
  to_port           = 443
  lifecycle {
    precondition {
      condition     = each.value != "${aws_eip.nat.public_ip}/32"
      error_message = "Do not copy the NAT EIP into allowed_client_cidrs: its HTTPS rule is managed separately."
    }
  }
}
# Conditional singleton: never key resources by an unknown EIP or ACM result.
resource "aws_vpc_security_group_ingress_rule" "alb_nat_https" {
  count             = var.enable_lab_https ? 1 : 0
  security_group_id = aws_security_group.alb.id
  cidr_ipv4         = "${aws_eip.nat.public_ip}/32"
  ip_protocol       = "tcp"
  from_port         = 443
  to_port           = 443
  description       = "Private runner HTTPS smoke through the lab NAT EIP only"
  lifecycle {
    precondition {
      condition     = !contains(var.allowed_client_cidrs, "${aws_eip.nat.public_ip}/32")
      error_message = "Do not copy the NAT EIP into allowed_client_cidrs: that would create duplicate AWS ingress rules."
    }
  }
}
resource "aws_vpc_security_group_egress_rule" "alb_pods" {
  security_group_id            = aws_security_group.alb.id
  referenced_security_group_id = aws_security_group.nodes.id
  ip_protocol                  = "tcp"
  from_port                    = 8080
  to_port                      = 8080
}
resource "aws_vpc_security_group_ingress_rule" "pods_from_alb" {
  security_group_id            = aws_security_group.nodes.id
  referenced_security_group_id = aws_security_group.alb.id
  ip_protocol                  = "tcp"
  from_port                    = 8080
  to_port                      = 8080
}
resource "aws_vpc_security_group_ingress_rule" "nodes_internal" {
  security_group_id            = aws_security_group.nodes.id
  referenced_security_group_id = aws_security_group.nodes.id
  ip_protocol                  = "-1"
  description                  = "VPC CNI pod traffic, DNS and intra-cluster services only"
}
resource "aws_vpc_security_group_ingress_rule" "nodes_control_plane" {
  for_each                     = toset(["443", "10250", "9443"])
  security_group_id            = aws_security_group.nodes.id
  referenced_security_group_id = aws_security_group.cluster.id
  ip_protocol                  = "tcp"
  from_port                    = tonumber(each.key)
  to_port                      = tonumber(each.key)
  description                  = "API extension, kubelet or ALB controller admission webhook"
}
resource "aws_vpc_security_group_ingress_rule" "cluster_nodes" {
  security_group_id            = aws_security_group.cluster.id
  referenced_security_group_id = aws_security_group.nodes.id
  ip_protocol                  = "tcp"
  from_port                    = 443
  to_port                      = 443
}
resource "aws_vpc_security_group_egress_rule" "cluster_nodes" {
  security_group_id            = aws_security_group.cluster.id
  referenced_security_group_id = aws_security_group.nodes.id
  ip_protocol                  = "-1"
}
resource "aws_vpc_security_group_egress_rule" "nodes" {
  security_group_id = aws_security_group.nodes.id
  cidr_ipv4         = "0.0.0.0/0"
  ip_protocol       = "-1"
  description       = "Private nodes reach AWS APIs, ECR/S3 and package downloads through the lab NAT"
}
