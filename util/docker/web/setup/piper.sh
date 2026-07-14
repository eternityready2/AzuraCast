#!/bin/bash
set -e
set -x

# Runtime deps for Piper TTS (OpenMP for ONNX Runtime)
apt-get install -y --no-install-recommends libgomp1 python3 python3-pip

# Install huggingface-hub for reliable downloads in CI/CD environments
pip3 install --break-system-packages huggingface-hub

# Per-architecture Piper install
ARCHITECTURE=x86_64
if [[ "$(uname -m)" = "aarch64" ]]; then
    ARCHITECTURE=aarch64
fi

PIPER_VERSION="2023.11.14-2"

wget -O /tmp/piper.tar.gz \
  "https://github.com/rhasspy/piper/releases/download/${PIPER_VERSION}/piper_linux_${ARCHITECTURE}.tar.gz"

mkdir -p /usr/local/share/piper
tar -xzf /tmp/piper.tar.gz -C /usr/local/share/piper/ --strip-components=1
ln -sf /usr/local/share/piper/piper /usr/local/bin/piper
chmod a+x /usr/local/share/piper/piper

# Download voice models using huggingface-cli (handles CDN auth/retries automatically)
# This fixes 403 Forbidden errors from HuggingFace CDN in GitHub Actions
export HF_HOME=/tmp/hf_cache

huggingface-cli download rhasspy/piper-voices \
  en/en_US/lessac/medium/en_US-lessac-medium.onnx \
  en/en_US/lessac/medium/en_US-lessac-medium.onnx.json \
  en/en_US/joe/medium/en_US-joe-medium.onnx \
  en/en_US/joe/medium/en_US-joe-medium.onnx.json \
  en/en_US/ryan/medium/en_US-ryan-medium.onnx \
  en/en_US/ryan/medium/en_US-ryan-medium.onnx.json \
  --local-dir /usr/local/share/piper-voices

rm -f /tmp/piper.tar.gz
rm -rf /tmp/hf_cache
