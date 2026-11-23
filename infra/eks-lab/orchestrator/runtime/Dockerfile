ARG BASE_IMAGE
FROM ${BASE_IMAGE}
# The reviewed base must already contain all tools; no package download at build.
RUN command -v bash && command -v python3 && command -v aws && command -v kubectl \
    && python3 -c 'import sys; assert sys.version_info >= (3, 10)' \
    && aws --version && kubectl version --client
COPY deploy.py /opt/manaleague-deploy/deploy.py
COPY deploy.zip deploy.sha256 /opt/manaleague-deploy/
ENTRYPOINT ["python3", "-I", "-B", "/opt/manaleague-deploy/deploy.py"]
