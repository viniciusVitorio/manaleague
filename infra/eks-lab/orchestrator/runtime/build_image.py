#!/usr/bin/env python3
"""Build locally from an operator-reviewed, digest-pinned toolchain image."""
import argparse
import importlib.util
from pathlib import Path
import re
import shutil
import subprocess
import tempfile

HERE = Path(__file__).resolve().parent
spec = importlib.util.spec_from_file_location('runtime_validation', HERE / 'deploy.py')
runtime = importlib.util.module_from_spec(spec)
spec.loader.exec_module(runtime)
BASE = re.compile(r'[a-z0-9][a-z0-9._:-]*/[a-z0-9][a-z0-9._/-]*@sha256:[a-f0-9]{64}\Z')
TAG = re.compile(r'[a-z0-9][a-z0-9._/-]*(?::[A-Za-z0-9_][A-Za-z0-9_.-]{0,127})?\Z')


def build(base, tag, archive, expected):
    if not BASE.fullmatch(base) or not TAG.fullmatch(tag):
        raise ValueError('base must be digest-pinned and tag must be a valid image name')
    with tempfile.TemporaryDirectory(prefix='lab-image-context-') as directory:
        for name in ('Dockerfile', 'deploy.py'):
            shutil.copyfile(HERE / name, Path(directory) / name)
        context = Path(directory)
        shutil.copyfile(archive, context / 'deploy.zip')
        runtime.extract_archive(context / 'deploy.zip', context / 'verified', expected)
        shutil.rmtree(context / 'verified')
        (context / 'deploy.sha256').write_text(expected + chr(10), encoding='ascii')
        subprocess.run(['docker', 'build', '--network=none', '--platform=linux/amd64',
                        '--build-arg', 'BASE_IMAGE=' + base, '--tag', tag, directory], check=True)


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--base', required=True, help='reviewed toolchain image by sha256 digest')
    parser.add_argument('--tag', required=True, help='local output image tag')
    parser.add_argument('--archive', required=True, help='reviewed deploy ZIP; never include secrets')
    parser.add_argument('--sha256', required=True, help='independently reviewed archive SHA-256')
    args = parser.parse_args()
    try:
        build(args.base, args.tag, args.archive, args.sha256)
    except (ValueError, OSError, subprocess.CalledProcessError) as exc:
        parser.exit(1, 'image build failed: ' + str(exc) + '\n')


if __name__ == '__main__':
    main()
