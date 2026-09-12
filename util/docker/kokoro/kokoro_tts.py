#!/usr/bin/env python3
import json
import os
import re
import subprocess
import sys
from pathlib import Path

import numpy as np
import soundfile as sf
from kokoro_onnx import Kokoro

MODEL_PATH = "/opt/kokoro/kokoro-v1.0.onnx"
VOICES_PATH = "/opt/kokoro/voices-v1.0.bin"
REAL_PIPER_BIN = "/usr/local/share/piper/piper"

# Keep the outer PHP process comfortably below AiDjGenerator's 90-second ceiling.
KOKORO_TIMEOUT_SECONDS = 35
PIPER_TIMEOUT_SECONDS = 40
KOKORO_CHUNK_CHARS = 260
CHUNK_PAUSE_SECONDS = 0.12

PIPER_FEMALE = "/usr/local/share/piper-voices/en/en_US/lessac/medium/en_US-lessac-medium.onnx"
PIPER_MALE_CANDIDATES = [
    "/usr/local/share/piper-voices/en/en_US/joe/medium/en_US-joe-medium.onnx",
    "/usr/local/share/piper-voices/en/en_US/ryan/medium/en_US-ryan-medium.onnx",
]


def split_text(text: str, limit: int = KOKORO_CHUNK_CHARS) -> list[str]:
    """Split a complete script into sentence/word-safe Kokoro chunks without dropping text."""
    clean = " ".join(text.strip().split())
    if not clean:
        return []
    if len(clean) <= limit:
        return [clean]

    sentences = re.split(r"(?<=[.!?])\s+", clean)
    chunks: list[str] = []
    current = ""

    def flush_current() -> None:
        nonlocal current
        if current:
            chunks.append(current)
            current = ""

    for sentence in sentences:
        sentence = sentence.strip()
        if not sentence:
            continue

        if len(sentence) <= limit:
            candidate = sentence if not current else f"{current} {sentence}"
            if len(candidate) <= limit:
                current = candidate
            else:
                flush_current()
                current = sentence
            continue

        # A single very long sentence still has to be preserved. Word-wrap it into
        # bounded chunks rather than truncating the ending or dropping the fact/payoff.
        flush_current()
        words = sentence.split()
        piece = ""
        for word in words:
            candidate = word if not piece else f"{piece} {word}"
            if len(candidate) <= limit:
                piece = candidate
            else:
                if piece:
                    chunks.append(piece)
                piece = word
        if piece:
            chunks.append(piece)

    flush_current()
    return chunks


def kokoro_child(text: str, voice: str, output_path: str, speed: float) -> None:
    chunks = split_text(text)
    if not chunks:
        raise RuntimeError("No Kokoro text to render")

    kokoro = Kokoro(MODEL_PATH, VOICES_PATH)
    rendered: list[np.ndarray] = []
    expected_rate: int | None = None

    for index, chunk in enumerate(chunks):
        samples, sample_rate = kokoro.create(chunk, voice=voice, speed=speed, lang="en-us")
        if expected_rate is None:
            expected_rate = sample_rate
        elif sample_rate != expected_rate:
            raise RuntimeError("Kokoro returned inconsistent sample rates")

        rendered.append(np.asarray(samples))
        if index < len(chunks) - 1:
            rendered.append(
                np.zeros(int(sample_rate * CHUNK_PAUSE_SECONDS), dtype=np.asarray(samples).dtype)
            )

    if expected_rate is None or not rendered:
        raise RuntimeError("Kokoro produced no audio")

    sf.write(output_path, np.concatenate(rendered), expected_rate)


def run_kokoro_with_timeout(text: str, voice: str, output_path: str, speed: float) -> tuple[bool, str]:
    command = [
        sys.executable,
        __file__,
        "--kokoro-child",
        text,
        voice,
        output_path,
        str(speed),
    ]

    try:
        result = subprocess.run(
            command,
            stdout=subprocess.PIPE,
            stderr=subprocess.PIPE,
            text=True,
            timeout=KOKORO_TIMEOUT_SECONDS,
            check=False,
        )
    except subprocess.TimeoutExpired:
        return False, f"Kokoro exceeded {KOKORO_TIMEOUT_SECONDS}s"

    if result.returncode != 0:
        detail = (result.stderr or result.stdout or "Kokoro child failed").strip()
        return False, detail[-1000:]

    if not Path(output_path).is_file() or Path(output_path).stat().st_size == 0:
        return False, "Kokoro produced no audio"

    return True, ""


def choose_piper_model(voice: str) -> str:
    male_voice = voice.startswith(("am_", "bm_", "em_", "hm_", "im_", "jm_", "pm_", "zm_"))
    candidates = PIPER_MALE_CANDIDATES if male_voice else [PIPER_FEMALE]

    for candidate in candidates:
        if Path(candidate).is_file():
            return candidate

    # Last-resort cross-gender fallback is preferable to a completely silent DJ.
    all_candidates = [PIPER_FEMALE, *PIPER_MALE_CANDIDATES]
    for candidate in all_candidates:
        if Path(candidate).is_file():
            return candidate

    raise RuntimeError("No Piper fallback voice model is installed")


def run_piper_fallback(text: str, voice: str, output_path: str, speed: float) -> None:
    model = choose_piper_model(voice)

    # Call the real Piper binary directly here. AI News intentionally uses the
    # /usr/local/bin/piper timeout/retry wrapper, but Kokoro already owns a strict
    # 40-second fallback budget. Nesting the wrapper under that shorter timeout can
    # kill the wrapper while its real Piper child continues running as an orphan.
    command = [REAL_PIPER_BIN, "--model", model, "--output_file", output_path]

    if speed != 1.0:
        command.extend(["--length_scale", str(1.0 / speed)])

    result = subprocess.run(
        command,
        input=text,
        stdout=subprocess.PIPE,
        stderr=subprocess.PIPE,
        text=True,
        timeout=PIPER_TIMEOUT_SECONDS,
        check=False,
    )

    if result.returncode != 0:
        detail = (result.stderr or result.stdout or "Piper fallback failed").strip()
        raise RuntimeError(detail[-1000:])

    if not Path(output_path).is_file() or Path(output_path).stat().st_size == 0:
        raise RuntimeError("Piper fallback produced no audio")


def main() -> None:
    if len(sys.argv) > 1 and sys.argv[1] == "--kokoro-child":
        text = sys.argv[2]
        voice = sys.argv[3]
        output_path = sys.argv[4]
        speed = float(sys.argv[5]) if len(sys.argv) > 5 else 1.0
        kokoro_child(text, voice, output_path, speed)
        return

    text = " ".join(sys.argv[1].strip().split())
    voice = sys.argv[2]
    output_path = sys.argv[3]
    speed = float(sys.argv[4]) if len(sys.argv) > 4 else 1.0

    ok, reason = run_kokoro_with_timeout(text, voice, output_path, speed)
    engine = "kokoro"

    if not ok:
        # A stuck Kokoro render must not make a scheduled host disappear. Use a
        # local Piper voice as an emergency fail-open path; the next break will try
        # the configured Kokoro voice again normally. Preserve the complete script
        # already bounded by AiDjGenerator rather than cutting artist facts/payoffs.
        try:
            if os.path.exists(output_path):
                os.unlink(output_path)
            run_piper_fallback(text, voice, output_path, speed)
            engine = "piper_fallback"
        except Exception as exc:
            raise RuntimeError(f"Kokoro failed ({reason}); Piper fallback failed: {exc}") from exc

    print(json.dumps({
        "status": "ok",
        "output": output_path,
        "engine": engine,
        "kokoro_failure": reason if engine != "kokoro" else None,
    }))


if __name__ == "__main__":
    main()
