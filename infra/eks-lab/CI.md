# CI/CD do laboratório — SSDLC preservada e entrega EKS com gate

**Estado após a pausa autorizada de18/11/2026:** laboratório e aplicação removidos; gate false. Nenhuma CI ativa na eks-lab; artefatos e caches exclusivamente da branch foram removidos e a ausência foi lida de volta. O workflow compartilhado não foi desabilitado, e a CI/produção da main não foi interrompida. Não houve push desta atualização local, para não iniciar novas execuções durante a pausa. Evidência em `.local/stop-20261118/STOP_VERIFIED.json`.

## Preparação local de 21/11 para a apresentação de 25/11

Foi acrescentado um único `deploy-lab` à SSDLC, após `[test, security, sonar, waf]`. Só aceita push na eks-lab no repositório exato e chama `./.github/workflows/eks-lab.yml` do mesmo commit, sem secrets:inherit. Os cinco jobs anteriores foram preservados, inclusive Sonar e deploy EC2 main-only. A main real não foi editada.

Com o gate false: roda a CI normal e o gate hospedado; checks/builds/artifacts extras EKS, publish e deploy ficam skipped, sem fila self-hosted. Artifacts próprios da CI normal, como coverage/imagem WAF, continuam previstos; não confundir com exportação adicional EKS. Push pode consumir franquia/storage GitHub. Essa foi a etapa de preparação offline. O proprietário autorizou agora commit/push somente na eks-lab e validação da CI com gate false; não há autorização de deploy/recriação AWS.

`runner/session.py` prepara uma sessão efêmera por run: plan offline; start/cleanup futuros com conta/cluster/run/SHA/UIDs e confirmações explícitas. Labels são roteamento, não autorização. Não envia PAT, perfil AWS humano ou kubeconfig administrativo ao Pod. Cleanup retém Namespace/SA/policy, exigindo revisão/reset antes de nova sessão; não é ARC/pool/autoscaling. Networking/startup estrito do CNI/IMDS e ALB precisam ser verificados na janela autorizada; ACK não substitui ensaio.

`scripts/github_bindings.py` renderiza offline 17 variáveis a partir de outputs novos: força gate false e APP_URL vazio, sem ler senha de banco. Não escreve APIs nem prova ownership live. Procedimento em `apresentacao/QUARTA_FEIRA.md`, roteiro/diagrama em `apresentacao/`.

O gate real foi lido de volta e continua false. Código novo e testes locais não equivalem a CD remoto validado. Antes da demonstração, publicar somente a branch autorizada e verificar a entrega do SHA exato com o laboratório ativo.


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

## Entrega EKS reutilizável, chamada pela SSDLC

`.github/workflows/eks-lab.yml` tem `workflow_call` e preserva `workflow_dispatch`, sem gatilhos próprios de push/PR. A SSDLC chama a versão do mesmo commit depois dos gates, sem depender de botão na default branch nem inserir workflow na main. O caminho suportado por session.py é push da SSDLC, não dispatch direto. O botão antigo depende do registro na default branch; não habilitar gate para tentar registrar uma sessão de dispatch direto.

**O workflow não provisiona nem destrói infraestrutura.** O deploy manual de18/11 é histórico e foi removido depois. O laboratório está desligado e LAB_DEPLOY_ENABLED=false. Publicação do código/CI foi autorizada somente na eks-lab; registro do runner e entrega AWS ainda dependem da janela autorizada. Evidências e limites em VALIDATION.md.

O timeout de `/readyz` foi corrigido em `c49a2b5`: a sondagem DB é isolada em processo CLI com deadline1,5s, incluindo DNS/TLS/autenticação/SQL, mantendo `/up` independente. Após nova autorização Docker, as imagens da release `48f7dca` passaram pelo smoke real, fixture TLS e Trivy. A CI SSDLC dessa release ficou verde na run `44421131546`, inclusive o quality gate oficial Sonar; deploy EC2 skipped. Imagens testadas foram publicadas por digest e houve deploy manual no EKS, com duas réplicas Ready, migrations/seed fictício e ALB acessível. Testes reais de login/sessão entre Pods, Laravel/PDO verify-full, S3, WAF e WebSocket passaram. Evidências em `.local/app-deploy-20261118/`. Isso **não** registra runner nem habilita CD automático: o gate permanece false.


## Etapas após liberação autorizada

| Etapa | Gate false | Após futura autorização/configuração do gate true |
|---|---|---|
| PHP/PostgreSQL e JavaScript | Executa em serviços descartáveis do runner hospedado | Igual |
| Composer Audit, Terraform mock, schemas, lint, contratos e renderer do runner | Executa sem API AWS/Kubernetes | Igual |
| Build app/web, smoke HTTP/WAF/sessões/WebSocket e fixture TLS | Executa localmente no runner hospedado | Igual |
| Trivy filesystem e imagens HIGH/CRITICAL corrigíveis | Executa, falha se encontrar problemas | Igual |
| Exportação/upload das imagens testadas | **Não executa**: evita artifacts grandes sem consumidor | Exporta uma vez, com checksum e retenção curta |
| Publicação ECR por SHA/digest | **Skipped**, sem pedir credenciais AWS | Só com checks/images aprovados, branch correta e role OIDC exata |
| Deploy EKS | **Skipped**, nenhum runner/cluster é necessário para a CI | Runner dedicado, conta/cluster/RDS/namespace conferidos; migrations/seed/rollout/probes |

A tabela acima descreve a validação adicional explícita/entrega autorizada, não o push SSDLC com gate false. Nesse caso apenas a CI normal e o gate executam, e todos os jobs EKS adicionais são skipped. Com gate true, a chamada após a SSDLC faz checks → images → publish → deploy. Deploy usa a label exclusiva eks-lab-<run_id>.

## Reprodutibilidade e segurança

- A CI SSDLC mantém as referências de Actions da `main`. No workflow adicional manual do lab, as Actions são fixadas por SHA completo; referências oficiais usadas na resolução estão em `tests/action-pins.json`. Esses pins devem ser revistos conscientemente, não trocados por um SHA inventado.
- No workflow adicional manual, Terraform1.17.2, kubectl1.37.0, Helm3.19.0 e Trivy0.76.2 são explícitos; o lockfile fixa o provider AWS. O mesmo `scripts/verify.sh` pode ser usado localmente, sem ser parte da CI automática SSDLC.
- No workflow adicional manual, o `.env` de teste é criado **somente a partir do `.env.example` público**; APP_ENV=testing, banco descartável e chave de fixture do PHPUnit. A CI SSDLC mantém o PostgreSQL de serviço e a configuração PHPUnit da main. Nunca copiar `.env`/credenciais de produção.
- O WAF mantém o usuário nginx não-root e recebe atualizações dos pacotes OS afetados por alertas corrigíveis. Não foram adicionadas exceções para esconder CVEs.
- Imagens têm allowlist de código, sem `.git`, state/tfvars privados, storage ou `.env` locais. O publish futuro usa as imagens que passaram pelo smoke, não recompila outro resultado.
- A boundary do controller impede retagging independente e limita LB por nome/subnets/SG e listeners/rules por ARN exato. TGs continuam usando ownership pela tag do cluster: a AWS não oferece condição VPC em CreateTargetGroup. Outro principal que pré-etiquete um TG estrangeiro como lab pode violar essa premissa. Isso não é uma fronteira absoluta contra comprometimento de conta; manter watch namespace/VPC, tags confiáveis e revisar isolamento antes de reativar. Essas roles existiam no laboratório que foi posteriormente removido; as mutações efetivamente usadas foram verificadas no deploy e nas sondas, sem afirmar isolamento absoluto contra comprometimento da conta.
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

Infraestrutura está removida. É necessário autorizar/recriar por plano novo e state vazio, atualizar outputs, fazer bootstrap e verificar rede/capacidade. Depois autorizar gate para o push confiável da SSDLC e abrir a sessão do runner por run, conforme runner/README.md. Não reutilizar endpoints/segredo antigo nem backups pré-destruição como state vivo. CD novo só será considerado validado após registro, IAM/rede e rollout reais. Fonte primária do mecanismo reutilizável: https://docs.github.com/en/actions/how-tos/reuse-automations/reuse-workflows .
