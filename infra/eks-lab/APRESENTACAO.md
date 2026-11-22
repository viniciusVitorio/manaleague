# Preparação da apresentação — quarta-feira, 25/11/2026

Preparado em 21/11/2026. Horário da apresentação ainda não informado.

## Limite atual de autorização

Preparar código, testes locais, CI/CD e documentação agora; não recriar AWS, não habilitar o gate, não agendar provisionamento automático, não alterar produção ou main. A criação de recursos pagos depende de confirmação do proprietário no dia. A preparação inicial foi sem commit/push. Depois da validação local, o proprietário autorizou publicar somente a eks-lab e acompanhar a CI, mantendo gate false e sem deploy AWS.

## Evidência conferida nesta preparação

- Branch local `eks-lab`, HEAD `ba2af7a`; notas locais da desmontagem ainda não commitadas. Preservar essas alterações e arquivos não relacionados.
- State local corrente do laboratório: zero instâncias gerenciadas. Não restaurar backups pré-destruição como state vivo.
- Dois testes de contratos de CI passaram: SSDLC automática na eks-lab, workflow EKS extra sem gatilhos automáticos.
- Testes da preparação em cópia limpa:91 contratos/scripts,5 contratos RDS e38 testes de runner;35 Terraform mock,20 manifests de aplicação e4 de runner, Helm/actionlint/sintaxe passaram. Rede/admissão/registro reais permanecem pendentes.
- API pública GitHub confirmou novamente a run `44421131546`, SHA `48f7dca402acd277f9449258ddadb91040ad5e92`, SSDLC Pipeline na eks-lab, completed/success. É evidência da release anterior, não da futura entrega.
- Histórico de aplicação real e desmontagem: `VALIDATION.md`, `.local/app-deploy-20261118/` e `.local/stop-20261118/`. O endereço antigo não representa um ambiente online.

**Ainda não validado:** CD ponta a ponta pelo GitHub. O deploy anterior foi manual; o runner dedicado não foi registrado. Testes locais do renderer não provam admissão, rede, execução do job ou rollout real.

## Preparar antes de 25/11 — sem infraestrutura paga

- [x] Fechar o caminho de entrega na branch eks-lab mantendo os testes, security, Sonar e WAF da SSDLC; deploy de produção deve continuar main-only.
- [x] Resolver o disparo do CD sem depender de incluir workflow novo na main. Não presumir que um workflow manual presente apenas na branch terá botão/dispatch utilizável; verificar o mecanismo escolhido antes da apresentação.
- [x] Preparar operação do runner efêmero restrito, com token temporário, identidade OIDC do lab e limpeza. Não instalar runner na estação Windows/WSL com credenciais do operador.
- [x] Revisar publicação de imagens imutáveis e vínculo do deploy ao mesmo SHA/artefato que passou pelos testes. Falhas de quality gate não podem ser contornadas.
- [x] Validar contratos, lint, Terraform mock, manifests e testes de segurança offline. Builds Docker, se necessários, devem continuar locais e descartáveis, sem publicar imagens ou aplicar recursos AWS.
- [x] Revisar o diff e verificar que state, tfvars, tokens, kubeconfigs e senhas não serão versionados. Commits continuam sob controle do proprietário.
- [x] Preparar diagrama, roteiro curto, comandos de observação e evidências de contingência. Não apresentar evidência histórica como ambiente atual nem testes mock como prova cloud.

## Provas adicionais e limites

O candidato não commitado foi exportado sem env/state/tfvars privados e validado na WSL com credenciais AWS desabilitadas. A ponte de credencial WSL→Windows foi exercitada com GET real do gate false, sem registrar runner. O kubectl real enviou os DeleteOptions/UID corretos a uma fixture HTTP local, não a um cluster. Consultas somente leitura confirmaram EKS/RDS/ALB ausentes e nenhum EC2 ativo/parado com Project do lab; state local vazio. Produção HTTP200 e checkout/state/refs preservados.

O código foi validado antes da publicação; o push autorizado deve ser verificado na SSDLC pelo SHA exato. Não confundir essa CI sem AWS com CD remoto validado. A main avançou em funcionalidades; não houve merge/rebase automático. Namespace/SA/policy do runner são retidos após cleanup e precisam de revisão/reset para nova sessão. Revisão independente final aprovada, sem bloqueadores de segurança/lógica; não substitui o ensaio cloud de quarta. Capturar nessa janela uma fixture sanitizada da resposta real do job reutilizável queued, confirmando nome composto, labels e runner_id antes do registro.

## Na quarta-feira — somente após confirmação

Reservar uma janela de algumas horas antes da apresentação; não deixar a primeira validação real do CD para o início da exposição. Horário de início será combinado quando o horário da apresentação for informado. Se for necessário ensaio cloud antes dessa data, obter nova autorização específica; não executar automaticamente.

1. Conferir identidade/conta/região, IP atual do operador, disponibilidade de versões e state vazio. Inventariar produção e proteger seus IDs sem modificá-la.
2. Gerar/revisar plano novo e estimativa atual; comparar todos os alvos com produção. Aplicar apenas o plano aprovado. Não executar scripts antigos datados de 18/11 indiscriminadamente.
3. Bootstrap e verificações de nó/addons/controller, RDS privado/TLS e storage exclusivo. Atualizar somente variáveis do Environment eks-lab a partir dos outputs novos; endpoints e segredo gerenciado terão identidade nova.
4. Registrar runner restrito somente após validar isolamento, capacidade, API privada e origem de saída permitida no ALB. Nunca abrir EKS/ALB globalmente para viabilizar o job.
5. Liberar o gate somente com autorização, executar a entrega do SHA aprovado e verificar cada job, migração, rollout, réplicas e imagem por digest. Só então declarar CD validado.
6. Validar aplicação: login/sessão entre Pods, `/up`, `/readyz`, WAF, WebSocket, Laravel/PDO TLS e S3. Registrar evidências novas e comparar produção com a referência inicial.
7. Fazer ensaio e capturar material de contingência. Se houver bloqueio real, informar e mostrar a evidência histórica identificada, sem fabricar sucesso.

## Sequência sugerida da demonstração

1. Separação produção/laboratório e arquitetura EKS → ALB/ModSecurity → Laravel → RDS/S3.
2. CI com testes, segurança, Sonar e WAF; mostrar uma execução do SHA apresentado.
3. CD: imagens testadas, OIDC, migrations e rollout do mesmo SHA.
4. Duas réplicas da aplicação e recuperação de Pod, se previamente ensaiada e explicitamente autorizada. Um único nó não demonstra failover de nó/AZ; não confundir réplicas com HPA.
5. Teste funcional e bloqueio WAF; explicar RDS privado e TLS.
6. Custos, limitações e estratégia de desligamento.

## Depois da apresentação

Desligamento deve ser combinado e autorizado. Para apagar o banco/dados novos, obter consentimento específico. Limpar runtime/ALB enquanto controllers estão ativos, depois storage e infraestrutura por plano revisado; verificar resíduos, backups e produção. Não programar destroy automático nem usar tags genéricas que também existam em produção.
