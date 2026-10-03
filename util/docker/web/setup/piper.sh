#!/bin/bash
set -e
set -x

# Runtime deps for Piper TTS (OpenMP for ONNX Runtime)
apt-get install -y --no-install-recommends libgomp1 python3 python3-pip

# Install huggingface-hub for reliable downloads in CI/CD environments
pip3 install --break-system-packages huggingface-hub

# Piper 1.8 (the piper-tts Python package). The old 2023.11.14 C++ binary cannot
# load voices trained with Piper 1.3+ (multi-codepoint phonemes such as "aɪ"),
# e.g. the station's own Kim Rasmussen voice. It is installed into its own
# --target directory so its numpy/onnxruntime never replace the copies Kokoro
# (Bella, Onyx) uses from system site-packages.
PIPER_TTS_VERSION="1.8.0"
pip3 install --break-system-packages --no-cache-dir --target /opt/piper-tts "piper-tts==${PIPER_TTS_VERSION}"

# Keep the stable internal path every caller already uses (the /usr/local/bin/piper
# timeout wrapper, Kokoro's Piper fallback, piper-real). Piper 1.8 accepts the old
# binary's --model/--output_file/--length_scale flags. 0.2s matches the old
# binary's default pause between sentences.
mkdir -p /usr/local/share/piper
cat > /usr/local/share/piper/piper <<'LAUNCHER'
#!/bin/sh
export PYTHONPATH=/opt/piper-tts
exec python3 -m piper --sentence_silence 0.2 "$@"
LAUNCHER

# /usr/local/bin/piper is the lightweight wrapper copied from util/docker/web/scripts
# before setup scripts run; it bounds a stalled inference and leaves time for one
# sentence-safe retry.
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

rm -rf /tmp/hf_cache

# The fork's own AI-cloned voice, Kim Rasmussen (Piper 1.5 model, 4 moods:
# warm/calm/upbeat/amused). Shipped like Kokoro's models, from a GitHub release,
# because each .onnx is over GitHub's 100 MB git file limit.
# en_US-kim-high.onnx holds all four moods (AI DJ "Auto Mood"); the others are
# single-mood copies.
KIM_URL="https://github.com/eternityready2/AzuraCast/releases/download/voice-kim-rasmussen-v1"
KIM_DIR=/usr/local/share/piper-station-voices/kim_rasmussen
mkdir -p "$KIM_DIR"
for voice in en_US-kim-high en_US-kim_warm-high en_US-kim_calm-high en_US-kim_upbeat-high en_US-kim_amused-high; do
  wget -q -O "$KIM_DIR/${voice}.onnx" "${KIM_URL}/${voice}.onnx"
  wget -q -O "$KIM_DIR/${voice}.onnx.json" "${KIM_URL}/${voice}.onnx.json"
done

