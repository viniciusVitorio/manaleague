# Relatório de validação local e AWS — ManaLeague EKS Lab com RDS

Escopo: branch `eks-lab`, worktree `D:/Projetos/manaleague-eks-lab`, base original `96bcc68`, atualizada na eks-lab até `62c3da6` em17/11. Após autorização, a infraestrutura AWS foi provisionada e verificada. **A aplicação na AWS não foi implantada; a infraestrutura está desmontada. CI e publicação na eks-lab foram autorizadas separadamente, sem mudança de DNS/produção e sem recriar AWS.** Separar os ensaios locais abaixo das sondas reais de infraestrutura.

## Estado atual — encerramento de custos autorizado

Em17/11/2026 o proprietário confirmou explicitamente exclusão da infraestrutura e do banco fictício. O teardown terminou com **0 adicionados,0 alterados,70 destruídos**, exit0. Leitura posterior confirmou state sem recursos gerenciados; EKS/RDS/EC2/EBS/ECR/S3/VPC/ENIs/ASG ausentes ou terminados; sem ALB/target groups/NAT, snapshots EBS/RDS, backups retidos, segredo RDS ativo ou log groups do lab. Provider OIDC compartilhado GitHub preservado; gatefalse; state de produção idêntico e health HTTP200.

Arquivo e código locais permanecem. Custos já incorridos e atrasos da fatura não são apagados; recursos de produção continuam sua cobrança normal. Evidências em `.local/teardown.log`, `.local/teardown-inventory.json` e `.local/teardown-verification.json`. **As seções de infraestrutura e primeira validação são históricas e não indicam ambiente ativo.**

## Validação pré-publicação da CI — 17/11/2026

Base atual `62c3da6` e candidato foram exportados para fontes limpas, sem `.env`, vendor, bancos ou configuração do operador herdados. Dependências instaladas separadamente e banco PostgreSQL descartável exclusivo:

- **399 testes PHP,4609 assertions**, tanto na base inalterada quanto no candidato; JavaScript passou em ambos. Composer Audit sem avisos.
- Candidato final: **67 testes** de scripts/workflows/boundary, **5 contratos**, **10 testes** do renderer offline; Terraform **34 testes mock**. Schemas:20 recursos base e4 do renderer; Helm/actionlint/sintaxe aprovados.
- App/web construídos da fonte revisada; wrapper real de smoke e4 casos TLS/PDO passaram. Trivy0.76.2 filesystem e ambas imagens: exit0 no gate HIGH/CRITICAL corrigíveis, sem exceções novas para mascarar CVEs.
- Dez pins de Actions foram conferidos contra commits reais nos respectivos repositórios upstream. A revisão independente identificou uma permissão excessiva de atualização da rede do ALB; grants separados e testes negativos corrigem foreign/mixed/missing/empty IDs, sem chamadas IAM reais.
- A verificação auxiliar de limpeza inicialmente comparou `No such` com a resposta minúscula do Docker; o container já removido foi reconferido por ID/nome e o parser foi corrigido. Não houve falha das suítes de aplicação. A validação Terraform na WSL usou temp nativo curto após diagnosticar o limite AF_UNIX de um TMPDIR Windows longo.
- A execução GitHub deve ser conferida no **SHA exato publicado**: checks/images/gate bem-sucedidos, publish/deploy skipped e gatefalse. Esses resultados não comprovam CD real na AWS.

## Primeira validação local — base original96bcc68

| Verificação | Resultado observado |
|---|---|
| Laravel/PHP com PostgreSQL17.6 descartável e exclusivo | **396 testes passaram, 4547 assertions**, exit0 |
| Laravel/PHP com SQLite em memória | 393 passaram, 3 pulados; posteriormente executados todos os396 com PostgreSQL |
| JavaScript existente | **16 passaram, zero falhas** |
| Testes de scripts/workflows/endpoint/RDS do laboratório | **52 passaram**; comandos cloud explicitamente simulados/negados |
| Contratos de runtime/RDS/RBAC/CA | **5 passaram**, sem chamadas AWS |
| Terraform fmt/init com backend desativado/validate | Passaram em Windows e Linux; provider AWS6.73.0 com lockfile |
| Terraform test | **33 passaram, zero falhas**, `mock_provider "aws"`, sem criar recursos |
| Kustomize e kubeconform strict (schema1.37) | **20 recursos válidos, zero inválidos/erros/pulados** |
| Helm strict lint e template do Controller3.6.3 | 1 chart aprovado; flags namespace/VPC/região/backendSG conferidas |
| Política IAM oficial do Controller | SHA256 conferido contra versão vendorizada |
| actionlint | Os três workflows passaram |
| Sintaxe Bash | Scripts de runtime/local/operação passaram |
| Wrapper PowerShell | `lab.ps1 validate` executado de verdade e aprovado após correção do caminho Windows→WSL |
| Composer Audit | `No security vulnerability advisories found.` |
| Docker app/web | Ambos targets construídos; ensaio CI usando as mesmas imagens terminou exit0 |
| Preservação da produção | Nenhuma alteração nos arquivos rastreados do checkout original; branch e arquivos pendentes preservados |

## Infraestrutura e sondas reais AWS — verificadas em 17/11/2026

- Log do apply: **70 adicionados, 0 alterados, 0 destruídos**. EKS `manaleague-eks-lab` ACTIVE, versão1.37; RDS exclusivo available/privado. State local próprio, serial71, com cópia privada de recuperação na WSL.
- Um nó `t3.medium` Ready; CNI, CoreDNS, kube-proxy, EBS CSI e AWS Load Balancer Controller Running/Ready. Bootstrap de namespace, ServiceAccount, RBAC e Secrets reservados passou.
- Job temporário com **psql conectado ao RDS real**, CA oficial e `sslmode=verify-full`: retornou banco/usuário exclusivos, `ssl=t`, **TLSv1.3**, e transação de teste revertida. Não equivale ao PDO/Laravel real já implantado.
- Job temporário sob ServiceAccount `lab-app`: STS confirmou a role IRSA exclusiva `manaleague-eks-lab-app`, sem credenciais/admin do operador. Put/get em objeto próprio `avatars/.lab-preflight/` passaram com **AES256**; a remoção do objeto foi confirmada via HeadObject404.
- Jobs, Secret de senha temporário e ConfigMap de CA dessas sondas foram removidos por nome/UID exatos. Nenhuma senha registrada em logs/artefatos.
- Environment GitHub `eks-lab` restrito à branch,16 variáveis verificadas e atualizadas com outputs reais (incluindo URLs ECR completas). Gate continua **false**. Metadados de `production` permaneceram idênticos.
- Evidências locais ignoradas: `.local/live-bootstrap-verification.json`, `.local/live-database-storage-probes.json`, `.local/github-live-bindings.json` e `.local/first-apply.log`.
- **Essas sondas não são uma release do Laravel nem um teste do ALB da aplicação.** O resultado da CI deve ser conferido separadamente no SHA publicado; CD real permanece pendente.

## Ensaio Docker real

O wrapper **real da CI**, `scripts/smoke.sh`, foi executado com Docker na WSL, não apenas analisado:

- PostgreSQL **local descartável** no modo explícito local: migrations e seed fictício; sete contas verificadas; retries sem duplicar torneios. Não é um endpoint RDS real.
- Imagens sem `.env`, `.git` ou dados privados locais; FPM restrito ao namespace de rede do Pod simulado.
- SQLi e XSS conhecidos:403 com marcador ModSecurity. Tráfego legítimo e exceção estreita de importação de deck passaram pelo WAF.
- Login feito em uma instância PHP; a mesma sessão autenticada foi aceita na segunda.
- Upgrade WebSocket101, hash de accept e frame Pusher `connection_established` reais.
- Guardas do seeder recusaram ambiente, driver, DB_URL, host/banco incorretos e ausência de senha.
- Cabeçalhos X-Real-IP/XFF forjados não se tornaram IP real nos logs.
- Banco realmente parado: `/readyz` retornou503 e `/up`200; banco restaurado.
- Containers, rede e volume descartáveis removidos; leitura posterior não encontrou containers/volumes do projeto de smoke.

Identidades das imagens efetivamente exercitadas:

```text
lab-app:dev sha256:ecbaaccabe4cdee6c7a3364b5c4b4bda6a1ecb0e68c92536eddba141221b5734
lab-web:dev sha256:1572f46676d91b812f82d326bedb61d41c8efd27881c2221d47560eb9523da8d
```

São IDs de imagens Docker locais, **não digests publicados no ECR**. Nenhuma imagem foi publicada.

## Revisão obrigatória para RDS

- Terraform aplicado após autorização: RDS17 Single-AZ db.t4g.micro,20GBgp3 criptografado, duas subnets privadas sem NAT, SG5432 só dos nós, senha gerenciada e backup1dia. Sem senha no tfvars/state, sem PostgreSQL em Pods.
- Deployment valida identificador/ARN/conta/região/endpoint/VPC privada/banco/usuário e lê somente o segredo RDS exato em AWSCURRENT. Laravel/PDO/libpq recebe verify-full e CA pública oficial.
- **28 configurações inseguras recusadas** em testes do runtime, incluindo modo ausente, DB_URL, host RDS de outro alvo/região e downgrade TLS. Dois casos RDS aceitos verificam só guardas; não se conectam à AWS.
- **4 testes TLS locais reais**: CA/hostname do fixture corretos passam; hostname errado, CA não confiável e CA ausente falham. Isso comprova uso de PGSSLROOTCERT pelo runtime instalado, não o certificado de um RDS real.
- Permissões StatefulSet/PVC do deploy reduzidas a leitura, para recusar recursos antigos. A pipeline executa contratos RDS e o fixture TLS antes de publicar imagens.
- Senhas gerenciadas rotacionam; Kubernetes só recebe atualização no deploy, sem reconciliação contínua. Essa limitação está documentada.
- Confirmação --delete-lab-data abrange RDS; defaults descartáveis excluem backups automáticos e não criam snapshot final. A exclusão real do RDS e da infraestrutura foi posteriormente autorizada e verificada na seção de encerramento de custos; antes disso, as sondas removeram somente seus recursos/objeto temporários.
- Os artefatos da etapa anterior sem RDS foram revalidados após a alteração; os IDs de imagem acima são os atuais.

## Correções encontradas durante integração

- ALB passou a usar SG Terraform com CIDRs obrigatórios; annotation isolada não protege SG customizado.
- Ingress passou a referenciar nome/SG exatos e não pedir mutação de SG pelo Controller.
- Role de publicação recebeu DescribeRepositories restrito aos ECRs do lab.
- Seed e bootstrap passaram a usar os mesmos dois Secrets reservados, com RBAC por nome.
- Limpeza reconhece e suspende os quatro CronJobs e verifica owners/UIDs antes de iniciar alterações.
- Deploy descobre a primeira URL do ALB, atualiza origens e reinicia consumidores por checksum antes do probe real.
- Kubectl e schemas foram alinhados ao EKS1.37.
- Wrapper CI copiava dependência ausente e montava configuração ilegível pelo nginx não-root; regressões reproduzidas e corrigidas antes do ensaio completo aprovado.
- Exportação/importação das imagens receberam `set -euo pipefail`.
- Wrapper PowerShell passou a normalizar barras antes de chamar wslpath; comando real validado.

## O que ainda não foi validado

- Deploy/migrations/seed do Laravel na AWS, scheduling/capacidade da aplicação completa e tolerância de nós.
- ALB/targets/SGs efetivos, enforcement das NetworkPolicies/CNI, HTTP/ModSecurity/WebSocket e uploads de avatar pelo Laravel (a sonda S3 usou AWS CLI).
- PDO do runtime conectado ao RDS real (a sonda real usou psql) e backup/restauração. A exclusão efetiva do RDS foi verificada no teardown.
- Federação GitHub OIDC→role de deploy em execução e runner dedicado configurado. Provider, subject e Environment foram preparados/verificados por API. A CI sem AWS deve ser conferida nos checks do SHA da branch, não confundida com CD real.
- Scans Trivy filesystem/imagens e Composer Audit passaram localmente; permanecem gates obrigatórios de cada execução da CI.
- Escala/recuperação/rollback do aplicativo na AWS. O teardown e suas consultas posteriores estão documentados acima; não significam fatura total da conta igual a zero.

O usuário autorizou explicitamente commit e push somente da eks-lab e execução da CI após o teardown. O objetivo atual é validar a pipeline sem recriar AWS; CD permanece desligado e seus testes reais continuam pendentes de nova autorização de custos. Comandos e pré-requisitos no README; custos e apresentação no ARCHITECTURE.md.
