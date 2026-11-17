# CI/CD do laboratório sem infraestrutura AWS ativa

A pipeline está em `.github/workflows/eks-lab.yml` e só responde a push/PR na branch `eks-lab` ou disparo manual. **O workflow não provisiona nem destrói infraestrutura.** A AWS continua desmontada; `LAB_DEPLOY_ENABLED=false` é o estado seguro padrão.

## Etapas e condições

| Etapa | Gate false | Após futura autorização/configuração do gate true |
|---|---|---|
| PHP/PostgreSQL e JavaScript | Executa em serviços descartáveis do runner hospedado | Igual |
| Composer Audit, Terraform mock, schemas, lint, contratos e renderer do runner | Executa sem API AWS/Kubernetes | Igual |
| Build app/web, smoke HTTP/WAF/sessões/WebSocket e fixture TLS | Executa localmente no runner hospedado | Igual |
| Trivy filesystem e imagens HIGH/CRITICAL corrigíveis | Executa, falha se encontrar problemas | Igual |
| Exportação/upload das imagens testadas | **Não executa**: evita artifacts grandes sem consumidor | Exporta uma vez, com checksum e retenção curta |
| Publicação ECR por SHA/digest | **Skipped**, sem pedir credenciais AWS | Só com checks/images aprovados, branch correta e role OIDC exata |
| Deploy EKS | **Skipped**, nenhum runner/cluster é necessário para a CI | Runner dedicado, conta/cluster/RDS/namespace conferidos; migrations/seed/rollout/probes |

PRs têm o job gate skipped, mas **não perdem os testes de imagens**: a condição explícita permite build/smoke/scans após checks bem-sucedidos. Falha/cancelamento dos checks não deve produzir release.

## Reprodutibilidade e segurança

- Actions do workflow do lab são fixadas por SHA completo; referências oficiais usadas na resolução estão em `tests/action-pins.json`. Esses pins devem ser revistos conscientemente, não trocados por um SHA inventado.
- Terraform1.17.2, kubectl1.37.0, Helm3.19.0 e Trivy0.76.2 são explícitos; o lockfile fixa o provider AWS. O mesmo `scripts/verify.sh` é usado localmente e na CI.
- O `.env` de teste é criado **somente a partir do `.env.example` público**; APP_ENV=testing, banco descartável e chave de fixture do PHPUnit. Nunca copiar `.env`/credenciais de produção.
- O WAF mantém o usuário nginx não-root e recebe atualizações dos pacotes OS afetados por alertas corrigíveis. Não foram adicionadas exceções para esconder CVEs.
- Imagens têm allowlist de código, sem `.git`, state/tfvars privados, storage ou `.env` locais. O publish futuro usa as imagens que passaram pelo smoke, não recompila outro resultado.
- A boundary do controller impede retagging independente e limita LB por nome/subnets/SG e listeners/rules por ARN exato. TGs continuam usando ownership pela tag do cluster: a AWS não oferece condição VPC em CreateTargetGroup. Outro principal que pré-etiquete um TG estrangeiro como lab pode violar essa premissa. Isso não é uma fronteira absoluta contra comprometimento de conta; manter watch namespace/VPC, tags confiáveis e revisar isolamento antes de reativar. Nenhuma dessas roles está ativa após o teardown.
- Não abrir PR desta branch inteira para merge automático na main: o laboratório é outro ambiente. As cópias dos workflows de produção têm guards adicionais, mas a main real não é modificada por esta entrega.

## Runner futuro

`runner/operator.py` é **somente um gerador offline**, não um serviço registrado ou online. Seus10 testes,4 schemas e a execução local das ferramentas pinadas não substituem admissão/rede/registro reais no EKS. Ler `runner/README.md` e `addons/RUNNER.md`, especialmente ALB allowlist, API privada, código confiável e vida útil de um único job.

Não instalar runner na WSL/Windows com as credenciais do operador. A identidade cloud do job é OIDC e a role exclusiva do lab; não montar a chave SSH, perfil AWS humano ou kubeconfig administrativo.

## Comandos locais

```powershell
wsl.exe -d manaleague-docker -- bash /mnt/d/Projetos/manaleague-eks-lab/infra/eks-lab/scripts/verify.sh
```

O smoke real constrói/usa Docker local e remove seus próprios recursos descartáveis; os comandos ficam no README. Nenhum desses resultados deve ser descrito como deploy real AWS.

## Para habilitar CD posteriormente

É necessária nova aprovação dos custos, recriação pelo Terraform, outputs reais atualizados no Environment, bootstrap e runner restrito validado. Confirmar novamente conta/região/branch, conectividade API/ALB e origem permitida; só então habilitar o gate. Não presumir que os endpoints/IDs/ARNs do ambiente removido continuam válidos. Backup de state pré-destruição é evidência histórica, não um ambiente ativo.
