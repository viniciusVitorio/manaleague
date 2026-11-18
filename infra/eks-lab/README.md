# ManaLeague — EKS Lab acadêmico

**Estado em 17/11/2026: laboratório DESMONTADO após consentimento explícito para encerrar custos e apagar o banco fictício.** O teardown removeu70 recursos, sem criar/alterar recursos. EKS, RDS, nó, discos, ECR, bucket, VPC e segredo gerenciado foram conferidos como removidos; não restaram snapshots/backups/log groups do lab nas consultas. Código/arquivos locais preservados, gate GitHubfalse, produção HTTP200 e state original inalterado. A publicação por commit/push foi autorizada posteriormente apenas na `eks-lab`, sem reativar AWS. Relatório atual em `.local/COST_STOP.md`; o restante deste README descreve como recriar após nova autorização, não um ambiente atualmente ativo.

O laboratório não substitui a produção. `main`, EC2/Compose, domínio, banco, volumes e credenciais atuais ficam fora de seu alcance. A branch `eks-lab` foi criada da `origin/main` no commit `96bcc68`, em um worktree próprio: `D:/Projetos/manaleague-eks-lab`. O checkout original `D:/Projetos/manaleague` continua na sua branch, com os arquivos locais preservados. A branch acadêmica não acompanha `origin/main` como upstream, para evitar push acidental à produção. Em17/11 foi atualizada por fast-forward até `62c3da6`, incorporando as funcionalidades atuais de decks/perfil sem alterar a branch main ou o checkout original.

## Conteúdo

| Diretório/arquivo | Responsabilidade |
|---|---|
| `terraform/` | VPC/sub-redes/SGs, EKS, managed nodes, RDS privado, IAM/IRSA/OIDC, ECR e S3 exclusivos |
| `addons/` | AWS Load Balancer Controller, política oficial versionada, Helm e validação |
| `docker/` | Dockerfile multi-target `app`/`web`, PHP-FPM e Nginx/ModSecurity |
| `runtime/` | Entrypoint de segurança, health checks e seed fictício idempotente |
| `kubernetes/base/` | Deployments, Services, Ingress, ConfigMap com endpoint RDS, NetworkPolicies, CronJobs |
| `kubernetes/bootstrap/` | Namespace, ServiceAccount IRSA, StorageClass gp3 e RBAC restrito |
| `kubernetes/jobs/` | Jobs explícitos de migrations e seed; não fazem parte do rollout base |
| `scripts/` | Validação, provisionamento/deploy/limpeza com travas, probe HTTP/WebSocket |
| `local/` | Ensaio Docker descartável com duas instâncias HTTP, banco próprio e Reverb |
| `tests/` | Regressões dos scripts e isolamento dos workflows |
| `runner/` | Gerador offline e testes do runner efêmero restrito; não registra nem cria recursos |
| `CI.md` | Etapas da CI sem AWS, gate de CD, Actions pinadas e limites da validação |
| `../../.github/workflows/pipeline.yml` | CI automática igual à main: testes, segurança, SonarCloud e WAF; deploy EC2 bloqueado na eks-lab |
| `../../.github/workflows/eks-lab.yml` | Workflow adicional **somente manual**: imagens testadas → ECR por SHA → EKS por digest; gate false |
| `ARCHITECTURE.md` | Diagrama Mermaid, decisões, custos e roteiro da apresentação |

## Arquitetura implementada

EKS **1.37**, em suporte padrão, VPC exclusiva com duas zonas, duas sub-redes públicas para ALB/nós e duas privadas para o DB subnet group RDS, sem NAT. Um `t3.medium` é o padrão econômico; `node_count=2` cria um nó por zona. ALB exclusivo com **Security Group de clientes autorizados**; nós não têm SSH nem portas públicas de aplicação/banco. O EKS tem API privada e pública com CIDRs específicos. Não é necessário modificar a VPC/DNS/servidor da produção.

Cada uma das duas réplicas HTTP tem Nginx + **ModSecurity/OWASP CRS** e PHP-FPM no mesmo Pod. Imagens têm cópia allowlist de código, sem `.env`, `.git`, storage, banco ou vendor locais. FPM9000 atende somente localhost do Pod. O Reverb tem uma réplica separada: apenas `/app/*` é roteado externamente; `/apps/*` é negado. **RDS PostgreSQL17 é requisito obrigatório:** instância db.t4g.micro Single-AZ privada,20GB gp3 criptografado, SG5432 somente dos nós EKS e TLS verify-full com CA oficial. Não existe PostgreSQL StatefulSet/Service/PVC na implantação EKS. EBS CSI continua configurado para Kubernetes, mas não é storage do RDS. Sessões/cache são compartilhados no banco; avatares usam S3 privado exclusivo com IRSA.

Migrations rodam em um Job antes do rollout; entrypoint não migra nem importa banco antigo. Seed usa transação/lock e marcador persistente: só aceita banco vazio ou já marcado como demo e nunca copia dados reais. `demo@manaleague.test` recebe a senha do Secret `DEMO_PASSWORD`, não a senha pública do seeder antigo. Reexecutar seed é no-op; trocar o Secret depois não troca automaticamente a senha já gravada.

**Limitações deliberadas:** RDS Single-AZ não é Multi-AZ/HA; o DB subnet group cobre duas zonas, sem pagar standby nesta configuração. Um nó não protege contra falha de máquina/AZ. HTTP no hostname do ALB é temporário, restrito e sem confidencialidade, apenas dados fictícios. AWS WAF gerenciado, Redis, EFS, autoscaler/HPA e NAT não foram adicionados. RDS é incluído como requisito do professor. Escalar manualmente não é autoscaling automático. S3/IRSA e ALB só poderão ser validados quando houver ambiente real.

## 1. Repetir a validação local agora (PowerShell)

Nesta máquina, Terraform, Helm, kubectl1.37, kubeconform e actionlint foram instalados na distro WSL `manaleague-docker`; downloads oficiais tiveram checksum conferido. Em outra máquina, instalar essas ferramentas e Python3.10+ antes de usar os comandos. A validação pode baixar providers, chart e schemas de seus registros públicos, mas **não acessa APIs AWS ou Kubernetes**.

```powershell
Set-Location D:\Projetos\manaleague-eks-lab

# Verificação integrada: testes de scripts, sintaxe, workflows, schemas,
# Terraform fmt/init sem backend/validate/test com provider mock e Helm.
wsl.exe -d manaleague-docker -- bash /mnt/d/Projetos/manaleague-eks-lab/infra/eks-lab/scripts/verify.sh

# Somente fmt/init sem backend/validate do Terraform; wrapper PowerShell real testado.
.\infra\eks-lab\scripts\lab.ps1 validate

# Testes de scripts isolados, sem ferramentas cloud.
python -m unittest discover -s infra/eks-lab/tests -v
```

As fixtures AWS em `terraform/tests/` são **mocks explícitos**, não recursos reais nem comprovação de permissões em execução. Testes do probe HTTP/WebSocket usam um servidor local de teste; não consultam o domínio de produção.

### Ensaio real das imagens, sem Kubernetes/AWS

Os comandos abaixo constroem as duas imagens locais e executam o mesmo wrapper de smoke usado na CI. A porta18080 deve estar livre. Só o projeto Docker descartável criado pelo wrapper é removido no final; a produção e outros projetos Docker não são parados.

```powershell
wsl.exe -d manaleague-docker -- bash -lc 'service docker start >/dev/null; sh /mnt/d/Projetos/manaleague-eks-lab/infra/eks-lab/local/build-wsl.sh; cd /mnt/d/Projetos/manaleague-eks-lab; bash infra/eks-lab/scripts/smoke.sh'
```

O teste usa um PostgreSQL local novo em modo LAB_DATABASE_MODE=local, credenciais aleatórias, duas instâncias PHP, WAF e WebSocket real. Na AWS, LAB_DATABASE_MODE=rds é obrigatório: não tratar este ensaio como um RDS real ou permitir fallback local em Kubernetes. Verifica login entre réplicas, SQLi/XSS bloqueados, CSRF/arquivos ocultos, guardas de seed e `/readyz=503` com banco desligado enquanto `/up=200`. Remove containers, rede e volume ao terminar. Avatares no ensaio são locais e efêmeros: não comprovam uploads S3 entre réplicas. O script de build WSL é específico deste caminho de worktree; em Linux/CI, executar os dois `docker build` do workflow a partir da raiz.

## 2. Configuração futura — NÃO cria recursos por si só

Só avançar depois de autorizar o provisionamento e revisar custo/escopo.

1. Copiar `terraform/terraform.tfvars.example` para `terraform/terraform.tfvars` (ignorado).
2. Preencher conta AWS real de12 dígitos, ARN IAM permanente do operador, ARN do provider GitHub OIDC existente e subject **exato** do repositório + Environment. Se o provider compartilhado não existir, ele precisa de preparação administrativa separada; este state não o cria nem o apaga.
3. Substituir os endereços TEST-NET de exemplo por CIDRs reais `/32`: operador/runner na API e professor/testadores/runner no ALB. `allowed_client_cidrs` do Terraform é a lista **autoritativa** no SG; a annotation `inbound-cidrs` não modifica um SG customizado.
4. Conferir versão EKS em suporte padrão e compatibilidade dos addons na região antes do plan real. Os addons gerenciados usam a versão padrão compatível retornada pela AWS; conferir outputs e, se desejado, fixar versões em `addon_versions`.
5. Usar um perfil AWS de laboratório com permissões de provisionamento revisadas. Nunca copiar credenciais da aplicação de produção. O perfil precisa existir **dentro da WSL** se os comandos rodarem lá.
6. Proteger e fazer backup do state local `terraform/.state/terraform.tfstate`. Sem esse arquivo não tentar recriar/destruir recursos existentes. Não reutilizar/importar o state de `terraform/` da raiz.

O acesso de operador é administrativo **apenas no cluster novo**; o deploy GitHub usa outro principal, com ECR restrito aos dois repositórios, DescribeCluster, leitura da instância RDS e somente do segredo gerenciado exato, além de RBAC do namespace. Nenhuma permissão para alterar RDS. A aplicação e os controllers usam roles IRSA próprias. NetworkPolicies são habilitadas no CNI; ainda falta testar sua aplicação real e o consumo de memória no nó.

## 3. Provisionamento futuro, somente com autorização

Os comandos são exemplos operacionais, **não foram executados** contra AWS. A flag `--allow-cloud` autoriza o comando localmente; ela não substitui a autorização do dono. Cada verbo exige conta/nome exatos. Windows e WSL não compartilham automaticamente as variáveis/perfis AWS.

Exemplo PowerShell que passa explicitamente o perfil e as variáveis à WSL, sem colocar chaves no comando:

```powershell
$ContaLab = 'SUBSTITUA_PELA_CONTA_REAL_DE_12_DIGITOS'
$AlvoLab = @('AWS_PROFILE=eks-lab', "AWS_ACCOUNT_ID=$ContaLab", 'AWS_REGION=us-east-1', 'EKS_CLUSTER_NAME=manaleague-eks-lab')
$ScriptLab = '/mnt/d/Projetos/manaleague-eks-lab/infra/eks-lab/scripts/lab.sh'
$ConfirmacaoLab = @('--allow-cloud', '--confirm-lab', 'manaleague-eks-lab', '--expected-account', $ContaLab)

# 1. Plan consulta a conta e salva um plano; não cria recursos.
wsl.exe -d manaleague-docker -- env @AlvoLab bash $ScriptLab plan @ConfirmacaoLab

# 2. Ler o plano salvo integralmente; nenhuma referência a recursos da produção é aceitável.
wsl.exe -d manaleague-docker -- terraform -chdir=/mnt/d/Projetos/manaleague-eks-lab/infra/eks-lab/terraform show .state/provision.tfplan

# 3. SOMENTE após revisão/autorização: cria recursos pagos a partir do plano/hash aprovados.
wsl.exe -d manaleague-docker -- env @AlvoLab bash $ScriptLab apply @ConfirmacaoLab

# 4. Operador instala namespace/RBAC/Secrets reservados e AWS Load Balancer Controller.
# EBS CSI Driver, CNI, CoreDNS e kube-proxy são addons gerenciados do Terraform.
wsl.exe -d manaleague-docker -- env @AlvoLab bash $ScriptLab bootstrap @ConfirmacaoLab
```

`bootstrap` mescla os valores Helm estáticos com os outputs dinâmicos (cluster, região, VPC, role). O Controller observa só o namespace do lab e não altera Security Groups: SGs/regras são do Terraform. Não usar esses comandos no Terraform da raiz.

## 4. GitHub Actions → ECR → EKS

O workflow **não cria o cluster a cada push**. Testes/scans/build/smoke acontecem em runners hospedados. Publicação usa OIDC e a mesma imagem testada, SHA imutável, digest conferido e artifact com checksum. Com o gate desligado, testes/build/smoke/TLS/scans continuam, mas exportação/upload de imagens são pulados para não armazenar artifacts sem uso. PRs continuam executando a validação de imagens mesmo com o gate skipped. Deploy usa um runner Linux dedicado `[self-hosted, linux, eks-lab]`, fora da produção, com saída estável autorizada na API **e no ALB**. Não executar PR não confiável nesse runner.

Em **Settings → Environments → New environment**, criar `eks-lab`, permitir só a branch `eks-lab` e configurar, depois do provisionamento:

| Variable | Valor/output |
|---|---|
| `LAB_DEPLOY_ENABLED` | `false` inicialmente; `true` só quando cluster/bootstrap/runner/secrets estiverem prontos |
| `AWS_ACCOUNT_ID` | `account_id` |
| `AWS_REGION` | `aws_region` |
| `AWS_ROLE_ARN` | `github_deploy_role_arn` |
| `EKS_CLUSTER_NAME` | `cluster_name` |
| `ECR_APP_REPOSITORY` | `ecr_app_repository_url`, URL completa |
| `ECR_WEB_REPOSITORY` | `ecr_web_repository_url`, URL completa |
| `AVATAR_BUCKET` | `avatar_bucket_name` |
| `APP_ROLE_ARN` | `app_role_arn` |
| `VPC_CIDR` | `vpc_cidr` |
| `VPC_ID` | `vpc_id`, para conferir que o RDS pertence à VPC exclusiva |
| `DB_HOST` | `rds_endpoint` (hostname, sem porta), nunca endpoint da produção |
| `RDS_MASTER_SECRET_ARN` | `rds_master_secret_arn`, gerado pelo RDS após provisionar |
| `SUBNET_IDS` | `subnet_ids` unidos por vírgula |
| `ALLOWED_CIDRS` | `allowed_client_cidrs` unidos por vírgula; manter igual ao SG do Terraform |
| `ALB_SECURITY_GROUP_ID` | `alb_security_group_id` |
| `APP_URL` | Opcional: vazio faz o deploy descobrir o hostname ALB; nunca domínio da produção |

**Secrets do Environment**, novos e exclusivos: `APP_KEY` (32bytes em formato Laravel base64) e `DEMO_PASSWORD` (24+ caracteres aleatórios). **Não configurar DB_PASSWORD no GitHub nem no tfvars:** RDS gera e guarda a senha no Secrets Manager; deploy confere instância/ARN/endpoint/VPC privada e lê a versão AWSCURRENT com IAM restrito. A senha só entra no Secret Kubernetes do lab, sem aparecer em logs/argumentos. Não usar defaults/reaproveitar secrets da produção. O bootstrap reserva `manaleague-secrets` e `manaleague-demo`; CI só atualiza esses dois nomes, não cria Secrets arbitrários nem altera RBAC.

Quando o gate estiver habilitado: migrations → seed idempotente → rollout → descoberta do ALB → ConfigMap com URL/origem finais → novo rollout por checksum → probe HTTP e WebSocket reais. Se um Pod não ficar saudável, o job falha. A primeira URL é descoberta automaticamente; não é necessário adivinhar hostname antes de criar o ALB. Se informar URL própria, DNS/TLS precisam ser preparados separadamente.

**Workflows de produção:** as cópias desta branch não executam deploy/sincronização de release em eventos acadêmicos; Daily tasks exige `main`. A `main` real não foi editada. Não mergear toda a branch do lab de volta à `main` sem revisar essas diferenças. No GitHub, restringir o Environment `production` à `main` como defesa adicional, pelo proprietário. Nesta entrega, o proprietário autorizou explicitamente commits/push somente na `eks-lab`. Isso não autoriza merge/push na `main` nem recriação de recursos AWS.

## 5. Rollback e alterações de funcionalidades

Incorporar mudanças futuras de `main` na `eks-lab` com revisão dos workflows, dependências, build e migrations. Não alterar produção para acomodar o lab. Deploys usam digests do ECR, não `latest`. Para rollback do laboratório, selecionar um SHA/digests já testados e preservados e executar novamente o deploy aprovado; ou usar rollout undo com o operador, restrito ao namespace. **Rollback de imagem não desfaz migrations.** Alterações de esquema devem ser retrocompatíveis; não usar migrate:fresh em banco que precise preservar.

Manter APP_KEY estável: trocá-la invalida sessões/dados criptografados. RDS/Secrets Manager gerencia a senha do banco e a rotaciona a cada7dias por padrão. O deploy lê a versão atual e atualiza Kubernetes; não existe sincronização contínua. Para sessões curtas, reexecutar deploy após rotação; manter o ambiente por semanas requer reconciliação/política adicional. Nunca alterar só o Secret Kubernetes ou logar a credencial. A credencial master simplifica o lab; separar usuário de aplicação e migrations é evolução de menor privilégio para produção. Jobs de uma release com falha exigem diagnóstico antes de removê-los/reexecutá-los; não há limpeza automática cega de falhas.

## 6. Destruição futura e controle de custos

1. Desabilitar `LAB_DEPLOY_ENABLED` e aguardar pipelines terminarem.
2. Guardar evidências/backups desejados. No console, revisar e remover explicitamente **só as imagens ECR e objetos/multipart uploads S3 pertencentes ao lab**, se não precisarem ser preservados. O script bloqueia destruição com ECR/S3 não vazios antes de remover runtime: não tem purge silencioso nem `force_destroy`.
3. Gerar e revisar plano de destruição do state exclusivo.
4. Confirmar exclusão de dados do lab. O script verifica ownership/UIDs, suspende CronJobs conhecidos, remove Ingress e aguarda ALB; para workloads/jobs e recusa StatefulSets/PVCs inesperados (não há banco Kubernetes na arquitetura RDS). Só então aplica o plano Terraform exclusivo que exclui RDS/EKS/rede. A confirmação --delete-lab-data inclui perda definitiva dos dados fictícios do RDS: por padrão sem snapshot final e com remoção dos backups automáticos. Rever a política/backups antes de criar dados que queira guardar.
5. Conferir ausência de ALB/target groups, RDS, backups/snapshots remanescentes, segredo gerenciado, nós, cluster, discos dos nós, SGs/ENIs, repositórios e bucket do laboratório. Faturamento pode atualizar depois; o script não comprova fatura zerada.

Usando as variáveis PowerShell da seção3, **somente quando a destruição for autorizada**:

```powershell
wsl.exe -d manaleague-docker -- env @AlvoLab bash $ScriptLab destroy-plan @ConfirmacaoLab
wsl.exe -d manaleague-docker -- terraform -chdir=/mnt/d/Projetos/manaleague-eks-lab/infra/eks-lab/terraform show .state/destroy.tfplan
wsl.exe -d manaleague-docker -- env @AlvoLab bash $ScriptLab destroy @ConfirmacaoLab --delete-lab-data
```

Se houver recursos desconhecidos, PVC sem identidade, ALB órfão, volume residual, state ausente ou cluster inacessível, o script **para** e pede diagnóstico; não remove finalizers/recursos pela força. Um ambiente parcialmente criado pode precisar de recuperação manual específica. Não selecionar recursos pela tag `academic`: produção também usa essa tag.

Estimativa base **com RDS**: um nó US$1,67/8h, US$5,02/24h, US$150,73/720h; dois nós US$2,07/8h, US$6,20/24h, US$185,88/720h. Inclui hipótese de1LCU, IPv4, EBS dos nós,2GB ECR, instância RDS,20GB gp3 RDS e1segredo. Não é teto: exclui S3, API Secrets Manager, CPU excedente RDS Unlimited, backups excedentes, tráfego/logs/impostos/câmbio. Detalhes/fontes e Mermaid em `ARCHITECTURE.md`. AWS Budgets é recomendado, mas alertas não são bloqueio de gasto. Desligar nós não elimina cobrança do EKS/ALB/RDS/armazenamento/Secrets Manager.

## Validação executada e o que falta

Resultados reais desta implementação estão em `VALIDATION.md` e `local/VERIFICATION.md`. Testes locais não comprovam permissões IAM em execução, addon compatível/região, criação do ALB, CNI/NetworkPolicies reais, RDS real/endpoint/TLS/credenciais/backups, IRSA/S3, runner, pipeline GitHub executada nem isolamento efetivo de duas contas AWS. Essas verificações precisam do futuro ambiente autorizado. Nenhum comando de provisionamento/deploy/destruição acima foi executado contra AWS.

Roteiro de apresentação, escala manual, autorreparo e evidências: `ARCHITECTURE.md`.
