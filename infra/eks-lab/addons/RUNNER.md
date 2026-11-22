# Runner do EKS Lab — decisão técnica e pré-requisitos

**Atualização de21/11/2026: preparação local, nenhum runner registrado.** O lab foi removido e gate segue false. Há renderer offline e session.py para futuro start/cleanup autorizado por run; ver ../runner/README.md e ../apresentacao/QUARTA_FEIRA.md. O controle roda na WSL confiável, mas o executor fica em Pod isolado, nunca na estação com credenciais. Código não foi commitado/publicado nesta preparação. A revisão técnica abaixo registra o desenho original; com session.py não criar/adotar previamente o namespace fixo: o helper verifica e cria seus próprios objetos.

## Decisão

Usar um runner Linux convencional em **Pod dedicado no próprio EKS Lab**, em namespace separado, aproveitando o nó já provisionado. Sem novo nó/VM/NAT/LoadBalancer/PVC e sem PAT administrativo permanente. O deploy não precisa de Docker, Helm ou Terraform: Bash, Git, certificados, Python, AWS CLI e kubectl são suficientes. Build/publicação permanecem nos runners hospedados existentes.

**Não usar a WSL do computador para o runner.** A documentação de segurança da Microsoft confirma que outra distro WSL, mesmo sem automount/interop e com usuário sem sudo, não é uma fronteira de segurança em relação ao Windows ou às outras distros do mesmo usuário. Esse computador contém credencial AWS de administrador e chave SSH da produção.

## Implementação mínima depois do commit/push

1. Namespace exclusivo com Pod Security Restricted. Pod sem root, sudo, capabilities, hostPath, hostNetwork, Docker socket ou discos do Windows. ServiceAccount sem RBAC privilegiado e token Kubernetes não montado automaticamente.
2. Usar imagens oficiais/pinadas e ferramentas verificadas. Dados/configuração em emptyDir, sem volume pago. Requests/limits explícitos; se faltar capacidade, ficar Pending, não escalar a infraestrutura automaticamente.
3. Aplicar e verificar NetworkPolicies: DNS necessário; API privada do EKS; HTTPS para GitHub/AWS; fluxo de verificação da aplicação. Bloquear IMDS, redes/interfaces de produção e destinos administrativos. Namespace sozinho não isola rede.
4. Registrar somente quando a release confiável estiver pronta, com token de registro temporário obtido pelo operador via GitHub. O token expira em uma hora; não passar o PAT/credencial do Git Credential Manager ao Pod, nem o perfil AWS humano ou kubeconfig administrativo.
5. O job atual exige self-hosted, linux e label exclusiva eks-lab-<run_id>. Depois de revisar infraestrutura/rede e autorizar gate/push da SSDLC, aguardar esse job e abrir a sessão correspondente; start confere gate true e job queued antes do registro. Confirmar por API o runner exato, init e Pod, sem inferir sucesso a partir de create.
6. A autorização cloud vem exclusivamente de GitHub OIDC para a role de deploy do lab. `lab.py` gera kubeconfig próprio validando ARN/endpoint/CA; não copiar a configuração administrativa do operador.
7. Executar somente código confiável. Labels não são autorização de segurança. Revisar proteções de branch/Environment, eventos de PR e outros workflows que possam selecionar o runner; não mudar silenciosamente configurações de produção/repositório para contornar essa revisão.
8. Validar o caminho HTTP do runner até o ALB. A allowlist atual do ALB inclui apenas o `/32` do operador; estar dentro do EKS não prova que o runner consegue acessar o ALB público. Autorizar somente a origem efetiva do lab com alteração Terraform revisada, sem abrir `0.0.0.0/0` ou permitir que o job altere SGs.
9. Executar e verificar a entrega do SHA/digests aprovados, depois limpar Job/Secret/identidade exatos. Namespace/SA/policy retidos exigem revisão/reset antes de nova sessão; não há pool/reconciler automático. CD remoto ainda precisa da janela autorizada.

## Limitações aceitas

- Sem persistência, perder/substituir o Pod implica novo registro e limpeza do runner offline anterior. O token de registro não pode ser reaproveitado indefinidamente.
- Depois de registrado, o runner utiliza identidade própria; expiração do token de registro não interrompe automaticamente o processo. Essa identidade continua sensível.
- Runner contínuo pode carregar comprometimento entre jobs. Para um laboratório público, preferir janela de execução curta e código aprovado; não tratá-lo como sandbox para PR arbitrário.
- ARC com GitHub App fica para uma evolução que realmente exija recuperação/autoscaling por job. Acrescenta componentes e chave persistente privilegiada do App; não é necessário para esta demo.
- Um runner dentro do cluster não reconstrói o cluster que o hospeda. Bootstrap e recuperação continuam pelo operador.
- Consumo de CPU/memória/tráfego é incremental, mesmo sem nova instância paga. Não declarar custo adicional zero de forma absoluta.

## Referências primárias verificadas

```text
https://raw.githubusercontent.com/microsoft/WSL/master/doc/docs/technical-documentation/security.md
https://docs.github.com/en/actions/how-tos/manage-runners/self-hosted-runners/add-runners
https://docs.github.com/en/actions/reference/security/secure-use
https://docs.aws.amazon.com/eks/latest/userguide/cluster-endpoint.html
https://docs.aws.amazon.com/eks/latest/userguide/cni-network-policy.html
```
