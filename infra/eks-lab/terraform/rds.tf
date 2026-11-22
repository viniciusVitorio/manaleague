# Required managed database; no PostgreSQL provider or secret-value data source.
resource "aws_subnet" "database" {
  count                   = 2
  vpc_id                  = aws_vpc.lab.id
  cidr_block              = cidrsubnet(var.vpc_cidr, 8, count.index + 10)
  availability_zone       = data.aws_availability_zones.available.names[count.index]
  map_public_ip_on_launch = false
  # Keep the database Terraform address for state compatibility; shared by EKS and RDS.
  tags = { Name = "${local.name}-private-${count.index + 1}" }
}
# Routes are managed exclusively by standalone aws_route resources.
resource "aws_route_table" "database" {
  vpc_id = aws_vpc.lab.id
  tags   = { Name = "${local.name}-private" }
}
resource "aws_route_table_association" "database" {
  count          = 2
  subnet_id      = aws_subnet.database[count.index].id
  route_table_id = aws_route_table.database.id
}
resource "aws_db_subnet_group" "lab" {
  name        = local.name
  description = "Two private subnets shared by EKS and RDS in the exclusive lab VPC"
  subnet_ids  = aws_subnet.database[*].id
  tags        = { Name = local.name }
}
resource "aws_security_group" "database" {
  name        = "${local.name}-database"
  description = "PostgreSQL TLS from lab EKS nodes only; no public ingress or initiated egress"
  vpc_id      = aws_vpc.lab.id
  egress      = []
  tags        = { Name = "${local.name}-database" }
}
resource "aws_vpc_security_group_ingress_rule" "database_nodes" {
  security_group_id            = aws_security_group.database.id
  referenced_security_group_id = aws_security_group.nodes.id
  ip_protocol                  = "tcp"
  from_port                    = 5432
  to_port                      = 5432
  description                  = "Only pods using the dedicated lab node security group"
}
resource "aws_db_parameter_group" "lab" {
  name        = local.name
  family      = "postgres17"
  description = "Require TLS explicitly (also PostgreSQL 17 default)"
  parameter {
    name  = "rds.force_ssl"
    value = "1"
    # Safe for reboot-sensitive parameters; the prepared group is attached at creation.
    apply_method = "pending-reboot"
  }
  tags = { Name = local.name }
}
resource "aws_db_instance" "lab" {
  identifier                      = local.name
  engine                          = "postgres"
  engine_version                  = "17"
  engine_lifecycle_support        = "open-source-rds-extended-support-disabled"
  auto_minor_version_upgrade      = true
  allow_major_version_upgrade     = false
  instance_class                  = "db.t4g.micro"
  allocated_storage               = 20
  max_allocated_storage           = 0
  storage_type                    = "gp3"
  storage_encrypted               = true
  multi_az                        = false
  publicly_accessible             = false
  network_type                    = "IPV4"
  db_subnet_group_name            = aws_db_subnet_group.lab.name
  vpc_security_group_ids          = [aws_security_group.database.id]
  parameter_group_name            = aws_db_parameter_group.lab.name
  db_name                         = "manaleague_eks_lab"
  username                        = "manaleague_lab"
  port                            = 5432
  manage_master_user_password     = true
  backup_retention_period         = 1
  copy_tags_to_snapshot           = true
  deletion_protection             = false
  skip_final_snapshot             = true
  delete_automated_backups        = true
  monitoring_interval             = 0
  database_insights_mode          = "standard"
  performance_insights_enabled    = false
  enabled_cloudwatch_logs_exports = []
  apply_immediately               = true
  tags                            = merge(local.tags, { Name = local.name })
  # Omitted KMS IDs use AWS-managed keys for RDS storage and Secrets Manager.
  # Terraform stores secret metadata only; never read or generate its value here.
  depends_on = [aws_route_table_association.database, aws_vpc_security_group_ingress_rule.database_nodes]
}
