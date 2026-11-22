# Operação de quarta-feira — 25/11/2026

**Não executar criação/deploy agora.** Este é um procedimento para a janela que será confirmada pelo proprietário. Não existe tarefa agendada. Infraestrutura paga, ativação do gate e eventual limpeza precisam de autorização no momento correspondente.

## A. Preparação local que pode ser repetida sem AWS

PowerShell, a partir deste worktree:

```powershell
Set-Location D:\Projetos\manaleague-eks-lab
python -I -B -m unittest discover -s infra/eks-lab/tests -p test_github_bindings.py -v
python -I -B -m unittest discover -s infra/eks-lab/runner/tests -v

# Validação integrada: providers/charts/schemas públicos, sem APIs AWS/Kubernetes.
# TMPDIR Linux curto evita o limite de sockets Unix do provider Terraform.
wsl.exe -d manaleague-docker --exec bash -lc 'mkdir -p /root/.cache/hermes-scratch/eks-presentation; export TMPDIR=/root/.cache/hermes-scratch/eks-presentation; bash /mnt/d/Projetos/manaleague-eks-lab/infra/eks-lab/scripts/verify.sh'
```

Não interpretar sucesso offline como registro de runner ou entrega cloud comprovada. Antes de publicar código, revisar diff e ausência de arquivos privados; nesta preparação inicial não há commit/push automático. A main avançou desde a base do laboratório; não foi feito merge/rebase automático das funcionalidades novas para preparar o CD.

## B. Pré-voo da janela autorizada

1. Conferir caller AWS, conta, região, saldo/estimativa e autorização. Perfil `eks-lab` é alias do operador; não é uma nova conta nem uma identidade automaticamente least-privilege.
2. Conferir state atual e AWS por nomes/IDs exatos; se houver recurso, reconciliar antes de criar. O state depois da desmontagem é vazio; backup anterior não descreve recursos vivos.
3. Guardar fingerprint atual de produção/main/checkout/state e observar saúde HTTP, sem alterar a produção. Não confiar somente no fingerprint antigo de18/11.
4. Atualizar CIDRs reais de operador/professor e verificar suporte/compatibilidade das versões. O endereço de casa não é necessariamente o da apresentação.
5. Gerar plano novo, comparar com IDs protegidos de produção e revisar custos. Guardar hash/log. Não reaplicar planos antigos de criação para corrigir erro de relatório.

Os comandos PowerShell de `../README.md`, seção3, já mostram `plan`, inspeção, `apply` do plano aprovado e `bootstrap`. Só usar no diretório `infra/eks-lab/terraform`, nunca no Terraform da raiz da produção.

## C. Recriar vínculos, sem reaproveitar endpoints antigos

Depois do apply/bootstrap autorizados, exportar **outputs atuais** e renderizar proposta de variáveis. Exemplo PowerShell; preencher a conta real, sem copiar credenciais para o comando:

```powershell
$ContaLab = 'SUBSTITUA_PELA_CONTA_REAL_DE_12_DIGITOS'
$BaseLab = 'D:\Projetos\manaleague-eks-lab\infra\eks-lab'
New-Item -ItemType Directory -Force "$BaseLab\.local\presentation-20261125" | Out-Null

# Terraform output lê o state corrente. Não exporta senhas/SecretString.
$Saida = wsl.exe -d manaleague-docker --exec terraform -chdir=/mnt/d/Projetos/manaleague-eks-lab/infra/eks-lab/terraform output -json
if ($LASTEXITCODE -ne 0) { throw 'Falha ao ler outputs; não atualizar o GitHub.' }
$Saida | Set-Content -Encoding utf8 "$BaseLab\.local\presentation-20261125\outputs.json"
python -I -B "$BaseLab\scripts\github_bindings.py" --input "$BaseLab\.local\presentation-20261125\outputs.json" --expected-account $ContaLab --region us-east-1
if ($LASTEXITCODE -ne 0) { throw 'Outputs rejeitados; nenhuma API foi alterada.' }
```

O renderer aceita somente clientes IPv4 públicos /32; isso é intencionalmente mais restrito que o Terraform, que aceita /24 ou mais restrito. Se um CIDR mais amplo já estiver autorizado, não o normalizar silenciosamente: revisar o contrato do renderer antes de sincronizar.

O renderer **somente produz JSON**, não escreve no GitHub/AWS. Valida17 variáveis propostas e o contrato lab/conta/região. Força `LAB_DEPLOY_ENABLED=false` e `APP_URL` vazio para não reutilizar hostname antigo. Não inclui APP_KEY, senha de banco ou DEMO_PASSWORD. Schema/nomes não provam frescor/ownership live: a instância/segredo/VPC ainda precisam ser conferidos contra a AWS, como faz o guard do deploy.

Aplicar a proposta exclusivamente em **Settings → Environments → eks-lab → Environment variables**, ou pela API exata desse Environment; ler cada valor de volta. Não alterar production nem variáveis globais do repositório. Manter os Secrets independentes já aprovados ou regenerá-los conscientemente, sem logar conteúdo. Não configurar DB_PASSWORD no GitHub.

## D. Preparar a sessão do runner

Consultar `../runner/README.md` para o contrato de `session.py` e seus comandos definitivos.

- Resolver endereços privados atuais da API **de dentro da VPC**; o DNS consultado no Windows pode devolver o caminho público. Usar somente /32s verificados.
- Verificar CNI/NetworkPolicy efetiva, IMDS bloqueado, isolamento de redes privadas, ausência de bypass e capacidade do nó. Um ACK do operador não substitui esses ensaios.
- Verificar origem pública efetiva do Pod para o ALB; revisar eventual acréscimo **desse /32 do lab** no Terraform. Não permitir que o deploy altere SG/API e não abrir0.0.0.0/0.
- Revisar risco de runner em repositório público e workflows que possam usá-lo. Label exclusiva por run ajuda roteamento, mas não é fronteira de autorização.
- Não registrar runner na estação Windows/WSL. A estação somente opera o registro; o executor fica no Pod isolado do lab. Não transmitir perfil AWS humano, PAT ou kubeconfig administrativo ao Pod.

## E. Entrega e evidência nova

Depois de todos os pré-requisitos e autorização do gate, publicar o commit revisado **somente na eks-lab**, executar SSDLC e acompanhar o run/SHA exatos. A chamada reutilizável roda depois dos gates existentes e usa arquivos do mesmo commit, sem inserir workflow na main.

Com gate false: apenas a CI normal e verificação do gate; sem ECR, espera por self-hosted ou recursos cloud. Com gate true: verificações EKS, build/smoke/TLS/scans, transferência com checksum, publish por SHA/digest e job deploy que exige label `eks-lab-<run_id>`. Registrar somente a sessão prevista para o job autorizado; retirar o Secret de registro após init e limpar identidade/Job exatos ao final.

Não declarar sucesso até ler: conclusões dos jobs, migration/seed, réplicas Ready, digests, URL nova, login/sessão, WAF, WebSocket, PDO/TLS e S3. Comparar produção com o pré-voo. Capturar evidência sem segredos. Se o CD não concluir, separar explicitamente falha do CD, deploy manual e histórico; não marcar verde artificialmente.

## F. Pós-apresentação

Combinar o encerramento. Confirmar exclusão dos dados fictícios novos antes de destroy. Desligar gate e cancelar somente runs eks-lab; limpar runner por identidade/UID, depois runtime/ALB com controllers ativos, storage próprio e plano de destruição revisado. Verificar resíduos, snapshots/backups e produção. Não apagar sharedOIDC ou recursos por tag genérica academic. Cobranças acumuladas podem aparecer com atraso.
