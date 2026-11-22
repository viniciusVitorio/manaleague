# ManaLeague EKS Lab — roteiro da demonstração

**Apresentação: quarta-feira, 25/11/2026. Horário a combinar.**

Este material fica pronto offline. O laboratório está pausado desde a desmontagem autorizada de18/11. O diagrama `arquitetura.html` é ilustrativo, não um painel de recursos ativos. Produção e main estão fora do escopo.

## Antes de sair para apresentar

- Combinar a janela de subida com algumas horas de antecedência. Não há agendamento de provisionamento/destroy.
- Confirmar IP de saída do local da apresentação. A rede da faculdade pode ser diferente da rede de casa: revisar API pública autorizada e ALB por CIDRs explícitos, nunca abrir o mundo.
- Ter o diagrama e este roteiro no computador. Screenshots/gravacão devem indicar data, SHA e se a evidência é histórica.
- Rodar CI/CD e ensaiar **antes** da exposição. Mostrar uma execução concluída do SHA apresentado; não depender de builds, downloads, Sonar e criação do RDS terminarem durante os minutos de fala.
- Não projetar terminal com `.env`, Secrets, kubeconfig, token de registro ou senha. Usar somente conta e dados fictícios no lab.

## Fala e o que mostrar

### 1. Problema e isolamento

> “O ManaLeague já tem produção em EC2 e Compose. Este trabalho não é uma migração da produção: é um segundo ambiente, descartável, para demonstrar EKS e entrega automatizada.”

Abrir `arquitetura.html`. Apontar VPC, banco, storage, roles e state exclusivos. Não há compartilhamento de dados nem mudança no domínio da produção.

### 2. Infraestrutura como código

Mostrar trechos de `terraform/cluster.tf`, `network.tf`, `rds.tf` e `storage.tf`, sem abrir tfvars ou state.

> “O Terraform descreve a infraestrutura. A criação exige um plano revisado pelo operador; não recriamos um cluster a cada push.”

Explicar duas zonas para subnets/ALB, sem NAT, um nó econômico e RDS privado Single-AZ. Dois Pods no mesmo nó **não** demonstram tolerância a falha do nó/AZ. DB subnet group com duas zonas **não** é RDS Multi-AZ. EBS CSI não armazena o RDS.

### 3. CI preservada

Na SSDLC do SHA apresentado, mostrar testes automatizados, auditoria/Trivy, Sonar com quality gate e WAF/Reverb.

> “Um teste ou gate que falha bloqueia a entrega. Não removemos scanners para obter verde. A CI continua separada da existência do cluster.”

O deploy EC2 da cópia da pipeline continua exclusivo de main. Não usar a execução de18/11 como se fosse validação da entrega nova.

### 4. CD e identidade

Mostrar a etapa `deploy-lab`, o gate do Environment e os jobs EKS da mesma revisão.

> “Só liberamos a publicação depois dos gates. A imagem que passou pelo smoke é a que publicamos, com SHA imutável e digest. O GitHub usa OIDC para uma role exclusiva, não uma chave AWS permanente no runner.”

Mostrar somente metadados de imagens, migration Job e rollout. O runner é temporário, por execução, em namespace separado; labels não são autorização nem tornam PR arbitrário seguro. Não montar credencial AWS do operador, chave SSH ou kubeconfig administrativo no Pod.

### 5. Aplicação e réplicas

Abrir a URL **nova**, obtida no deploy autorizado. Entrar na conta fictícia, mostrar um torneio e o fluxo funcional ensaiado. Mostrar duas réplicas Ready e Pods com `-o wide` no namespace do lab.

> “As réplicas compartilham sessões/dados no RDS; arquivos não dependem do disco de um Pod. Avatares do lab usam S3 privado com IRSA.”

Não afirmar teste visual de todo formulário se só o round-trip S3/PDO foi verificado.

### 6. Segurança demonstrável

Mostrar resultado de tráfego legítimo e de um SQLi/XSS conhecido **apenas no ALB do lab**. Não usar scanner no domínio real.

> “ModSecurity com OWASP CRS fica na borda da aplicação. O banco não é público; a aplicação valida CA e hostname usando TLS verify-full. AWS WAF gerenciado não foi contratado.”

`/up` é independente do banco; `/readyz` verifica prontidão com prazo limitado. Não parar o RDS diante da turma para demonstrar health check.

### 7. Autorreparo opcional

Somente com autorização e ensaio prévio: remover **um Pod HTTP identificado por UID**, observar substituição e Ready. Não apagar Deployment, namespace, nó, PVC ou banco. Não forçar deleção. Se demonstrar escala manual 2→3→2, confirmar capacidade antes; não chamar de HPA.

Esta etapa pode ser omitida se o tempo for curto. Mostrar evidência do ensaio é preferível a arriscar o ambiente sem necessidade.

### 8. Limitações e encerramento

> “O foco é demonstrar isolamento, pipeline e orquestração com custo controlado. Não é uma arquitetura de alta disponibilidade de produção: temos um nó, RDS Single-AZ e HTTP temporário restrito para dados fictícios.”

Explicar que parar Pods não elimina taxas de EKS/RDS/ALB/storage. A limpeza será por inventário/state exatos e com confirmação de perda dos dados fictícios, nunca pela tag genérica `academic`.

## Se alguma coisa falhar

- Sem internet/allowlist: usar diagrama e evidências identificadas; não abrir SG/API globalmente para salvar a apresentação.
- CI vermelha: mostrar o gate bloqueando e corrigir depois. Não apresentar deploy manual como CD do GitHub.
- Runner Pending/offline: verificar capacidade, políticas, DNS/API/ALB e registro; não subir nó/VM automaticamente nem usar a máquina de produção como runner.
- Bloqueio cloud: distinguir arquivos validados, testes locais, deploy histórico de18/11 e ambiente atual. Não fabricar execução bem-sucedida.

## Referências internas

- `../APRESENTACAO.md`: checklist da preparação.
- `../CI.md`: fluxo e gates.
- `../runner/README.md`: operação/limites do runner; comandos de sessão após integração.
- `../README.md`: comandos PowerShell para plano/bootstrap e variáveis do Environment.
- `../VALIDATION.md`: evidências verificadas e limites de cada fase.
