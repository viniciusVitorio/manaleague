"""Pure, stdlib-only identity contracts for the one isolated lab HTTPS origin."""
import re

HOSTNAME = 'eks.manaleague.com.br'
ORIGIN = 'https://' + HOSTNAME
SSL_POLICY = 'ELBSecurityPolicy-TLS13-1-2-Res-2021-06'
COOKIE = '__Host-manaleague_eks_lab_session'


def validate_certificate_arn(arn, *, account, region):
    """Reject lists, foreign identities and noncanonical ACM identifiers."""
    if (not isinstance(account, str) or not re.fullmatch(r'[0-9]{12}', account)
            or not isinstance(region, str) or not re.fullmatch(r'[a-z]{2}-[a-z]+-[0-9]', region)
            or not isinstance(arn, str)
            or not re.fullmatch(re.escape(f'arn:aws:acm:{region}:{account}:certificate/')
                                + r'[a-f0-9]{8}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{12}', arn)):
        raise ValueError('Exact same-account/region single ACM certificate ARN required')


def validate_app_url(url):
    """Allow exact HTTPS only; retain isolated HTTP origins without parser ambiguity."""
    from urllib.parse import urlparse
    if url == ORIGIN:
        return
    try:
        if not isinstance(url, str) or not url.isascii() or re.search(r'[\s\x00-\x1f\x7f\\?#%]', url):
            raise ValueError()
        parsed = urlparse(url)
        host = parsed.hostname or ''
        if (not url.startswith('http://') or parsed.scheme != 'http' or not host
                or host.endswith('.') or host == 'manaleague.com.br' or host.endswith('.manaleague.com.br')
                or parsed.username is not None or parsed.password is not None or parsed.path not in ('', '/')
                or parsed.netloc.endswith(':') or (parsed.port is not None and parsed.netloc.rsplit(':', 1)[1] != str(parsed.port))
                or parsed.query or parsed.fragment or (parsed.port is not None and not 1 <= parsed.port <= 65535)):
            raise ValueError()
    except (TypeError, ValueError):
        raise ValueError('APP_URL must be an isolated HTTP lab origin or the exact certified lab HTTPS origin') from None


def validate_certificate(certificate, tags, *, account, region, arn):
    """Accept only issued, lab-tagged, exclusively exact-host ACM certificates."""
    validate_certificate_arn(arn, account=account, region=region)
    try:
        expected = {'CertificateArn': arn, 'DomainName': HOSTNAME,
                    'SubjectAlternativeNames': [HOSTNAME], 'Status': 'ISSUED',
                    'Type': 'AMAZON_ISSUED'}
        if not isinstance(certificate, dict) or any(certificate.get(key) != value for key, value in expected.items()):
            raise ValueError()
        if not isinstance(tags, list):
            raise ValueError()
        tag_map = {}
        for tag in tags:
            key, value = tag['Key'], tag['Value']
            if not isinstance(key, str) or not isinstance(value, str) or key in tag_map:
                raise ValueError()
            tag_map[key] = value
        if tag_map.get('Project') != 'manaleague-eks-lab' or tag_map.get('Environment') != 'eks-lab':
            raise ValueError()
        in_use = certificate.get('InUseBy')
        prefix = f'arn:aws:elasticloadbalancing:{region}:{account}:loadbalancer/app/manaleague-eks-lab/'
        if not isinstance(in_use, list) or any(not isinstance(item, str) or not re.fullmatch(re.escape(prefix) + r'[a-f0-9]{16}', item) for item in in_use):
            raise ValueError()
    except (KeyError, TypeError, ValueError):
        raise ValueError('HTTPS certificate preflight failed: exact issued lab-owned host certificate required') from None
