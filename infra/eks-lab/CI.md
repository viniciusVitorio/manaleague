# CI/CD do laboratório — CI independente da infraestrutura AWS

## CI automática: o mesmo fluxo da main

A CI de push/PR da `eks-lab` usa `.github/workflows/pipeline.yml`, com o mesmo nome **SSDLC Pipeline** e os mesmos jobs da `main`: `test` → `security`, `sonar`, `waf` → `deploy`.

- PHPUnit/PostgreSQL com cobertura, Composer Audit/Trivy, SonarCloud com quality gate e os testes WAF/IP/Reverb foram mantidos como na `main`.
- O deploy EC2 continua restrito a **push na main**. Na `eks-lab`, ele fica **skipped**, sem ler credenciais de produção nem executar comandos AWS/SSH.
- As validações extras de Terraform/Kubernetes, builds do runtime EKS e o smoke específico do laboratório **não são mais uma segunda CI automática**.
- Esta alteração está somente na branch `eks-lab`; não modifica a `main` nem o ambiente de produção.

## Quality gate Sonar: exceção exclusiva do push `eks-lab`

O scanner original continua obrigatório. A API Sonar da organização retorna `403` para dados de branches não-main, embora publique o check oficial. Sem mudar plano, token ou branch analisada, apenas **push em `refs/heads/eks-lab`** usa `.github/scripts/verify-sonar-lab-check.py` dentro do job `sonar` existente. Outros eventos/refs mantêm `SonarSource/sonarqube-quality-gate-action@v1`; não há job/pipeline novo nem gate desativado.

O verificador consulta somente checks do `GITHUB_SHA` exato no repositório esperado, com `github.token` e permissões somente `contents: read`/`checks: read`. Exige nome `SonarCloud Code Analysis`, App ID oficial `12526`, URL HTTPS do dashboard com projeto configurado e branch `eks-lab`, início após marcador UTC pré-scanner em `GITHUB_ENV`, conclusão temporal válida, `completed`/`success` e título `Quality Gate passed`. Checks antigos/forjados não satisfazem o gate; ausência, ambiguidade, skipped/falha, API inválida/inacessível e timeout falham fechados. Paginação confere totais e IDs (máximo 1.000 checks); polling tem orçamento de 240s e a etapa timeout de 5 minutos. Não consulta a main nem altera o nome da branch para contornar restrições.

Testes offline: `python -m unittest discover -s infra/eks-lab/tests -p test_sonar_lab_check.py -v`. Evidência read-only no SHA `23c61f0fd75672d4bbb73963c1c3fe7f2d9a82a0`: check oficial `135180795606`, `completed/success`, título `Quality Gate passed`, URL da branch correta, início `2026-11-18T22:25:56Z` e conclusão `2026-11-18T22:26:20Z`. Essa evidência histórica não substitui executar/verificar a CI no SHA final da entrega.

## Workflow adicional EKS: somente manual

`.github/workflows/eks-lab.yml` agora tem apenas `workflow_dispatch`; não é disparado por push ou PR. Seu nome é **EKS lab checks and deploy (manual)**. Não foi disparado nesta correção. A disponibilidade do botão de disparo depende do registro do workflow no GitHub/default branch; não inserir esse arquivo na `main` sem autorização só para expor o botão.

**O workflow não provisiona nem destrói infraestrutura.** O laboratório foi recriado por nova autorização em18/11/2026; `LAB_DEPLOY_ENABLED=false` permanece desligado, sem deploy automático. Infraestrutura ativa não equivale a aplicação/ALB implantados; evidências atuais em `VALIDATION.md`.

O timeout de `/readyz` durante a queda do banco, observado na run `44183828568`, **continua pendente** no smoke específico do EKS. Alterar os gatilhos não corrige esse runtime e uma CI SSDLC verde não equivale a esse teste aprovado nem a deploy EKS validado. O teste Docker local bloqueado por aprovação não foi repetido.


## Etapas e condições do workflow adicional manual

| Etapa | Gate false | Após futura autorização/configuração do gate true |
|---|---|---|
| PHP/PostgreSQL e JavaScript | Executa em serviços descartáveis do runner hospedado | Igual |
| Composer Audit, Terraform mock, schemas, lint, contratos e renderer do runner | Executa sem API AWS/Kubernetes | Igual |
| Build app/web, smoke HTTP/WAF/sessões/WebSocket e fixture TLS | Executa localmente no runner hospedado | Igual |
| Trivy filesystem e imagens HIGH/CRITICAL corrigíveis | Executa, falha se encontrar problemas | Igual |
| Exportação/upload das imagens testadas | **Não executa**: evita artifacts grandes sem consumidor | Exporta uma vez, com checksum e retenção curta |
| Publicação ECR por SHA/digest | **Skipped**, sem pedir credenciais AWS | Só com checks/images aprovados, branch correta e role OIDC exata |
| Deploy EKS | **Skipped**, nenhum runner/cluster é necessário para a CI | Runner dedicado, conta/cluster/RDS/namespace conferidos; migrations/seed/rollout/probes |

As etapas acima pertencem **somente ao workflow adicional manual**. O push/PR normal executa a SSDLC Pipeline da main descrita no início; não dispara este workflow EKS.

## Reprodutibilidade e segurança

- A CI SSDLC mantém as referências de Actions da `main`. No workflow adicional manual do lab, as Actions são fixadas por SHA completo; referências oficiais usadas na resolução estão em `tests/action-pins.json`. Esses pins devem ser revistos conscientemente, não trocados por um SHA inventado.
- No workflow adicional manual, Terraform1.17.2, kubectl1.37.0, Helm3.19.0 e Trivy0.76.2 são explícitos; o lockfile fixa o provider AWS. O mesmo `scripts/verify.sh` pode ser usado localmente, sem ser parte da CI automática SSDLC.
- No workflow adicional manual, o `.env` de teste é criado **somente a partir do `.env.example` público**; APP_ENV=testing, banco descartável e chave de fixture do PHPUnit. A CI SSDLC mantém o PostgreSQL de serviço e a configuração PHPUnit da main. Nunca copiar `.env`/credenciais de produção.
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
