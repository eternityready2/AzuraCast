#!/usr/bin/env python3
import sys, json, soundfile as sf
from kokoro_onnx import Kokoro

MODEL_PATH = "/opt/kokoro/kokoro-v1.0.onnx"
VOICES_PATH = "/opt/kokoro/voices-v1.0.bin"

def main():
    text = sys.argv[1]
    voice = sys.argv[2]
    output_path = sys.argv[3]
    speed = float(sys.argv[4]) if len(sys.argv) > 4 else 1.0
    kokoro = Kokoro(MODEL_PATH, VOICES_PATH)
    samples, sample_rate = kokoro.create(text, voice=voice, speed=speed, lang="en-us")
    sf.write(output_path, samples, sample_rate)
    print(json.dumps({"status": "ok", "output": output_path, "sample_rate": sample_rate}))

if __name__ == "__main__":
    main()
