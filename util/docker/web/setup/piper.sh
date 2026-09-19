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

# Keep the upstream binary at a stable internal path. /usr/local/bin/piper is the
# lightweight wrapper copied from util/docker/web/scripts before setup scripts run;
# it bounds a stalled inference and leaves time for one sentence-safe retry.
ln -sf /usr/local/share/piper/piper /usr/local/bin/piper-real
chmod a+x /usr/local/share/piper/piper
chmod a+x /usr/local/bin/piper

# Curated English Piper voices only (medium quality) — keeps image size small
# for disk-constrained servers. Used by AI Newscaster and AI DJ Piper fallback.
# danny/kathleen only ship as low-quality, so kristin + alan fill those slots.
# Paths: en/{locale}/{name}/medium/{locale}-{name}-medium.onnx[.json]
export HF_HOME=/tmp/hf_cache

hf download rhasspy/piper-voices \
  en/en_US/lessac/medium/en_US-lessac-medium.onnx \
  en/en_US/lessac/medium/en_US-lessac-medium.onnx.json \
  en/en_US/ryan/medium/en_US-ryan-medium.onnx \
  en/en_US/ryan/medium/en_US-ryan-medium.onnx.json \
  en/en_US/joe/medium/en_US-joe-medium.onnx \
  en/en_US/joe/medium/en_US-joe-medium.onnx.json \
  en/en_US/amy/medium/en_US-amy-medium.onnx \
  en/en_US/amy/medium/en_US-amy-medium.onnx.json \
  en/en_US/kristin/medium/en_US-kristin-medium.onnx \
  en/en_US/kristin/medium/en_US-kristin-medium.onnx.json \
  en/en_GB/alan/medium/en_GB-alan-medium.onnx \
  en/en_GB/alan/medium/en_GB-alan-medium.onnx.json \
  en/en_GB/jenny_dioco/medium/en_GB-jenny_dioco-medium.onnx \
  en/en_GB/jenny_dioco/medium/en_GB-jenny_dioco-medium.onnx.json \
  en/en_GB/alba/medium/en_GB-alba-medium.onnx \
  en/en_GB/alba/medium/en_GB-alba-medium.onnx.json \
  voices.json \
  --local-dir /usr/local/share/piper-voices

rm -f /tmp/piper.tar.gz
rm -rf /tmp/hf_cache
