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

# Keep the outer PHP process comfortably below AiDjGenerator's 90-second ceiling.
# If the first render stalls or fails, retry the SAME Kokoro voice once. Never
# substitute a different Piper voice for a named AI DJ persona.
KOKORO_TIMEOUT_SECONDS = 35
KOKORO_RETRY_TIMEOUT_SECONDS = 35
KOKORO_CHUNK_CHARS = 260
CHUNK_PAUSE_SECONDS = 0.12


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


def run_kokoro_with_timeout(
    text: str,
    voice: str,
    output_path: str,
    speed: float,
    timeout_seconds: int,
) -> tuple[bool, str]:
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
            timeout=timeout_seconds,
            check=False,
        )
    except subprocess.TimeoutExpired:
        return False, f"Kokoro exceeded {timeout_seconds}s"

    if result.returncode != 0:
        detail = (result.stderr or result.stdout or "Kokoro child failed").strip()
        return False, detail[-1000:]

    if not Path(output_path).is_file() or Path(output_path).stat().st_size == 0:
        return False, "Kokoro produced no audio"

    return True, ""


def remove_partial_output(output_path: str) -> None:
    try:
        if os.path.exists(output_path):
            os.unlink(output_path)
    except OSError:
        pass


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

    ok, first_failure = run_kokoro_with_timeout(
        text,
        voice,
        output_path,
        speed,
        KOKORO_TIMEOUT_SECONDS,
    )
    retried = False

    if not ok:
        # Voice identity is part of the named AI DJ persona. A previous emergency
        # fallback rendered female DJs with Piper Lessac (and male DJs with a generic
        # Piper male voice), which made a host suddenly sound like somebody else.
        # Retry the configured Kokoro speaker once instead. If that also fails, let
        # the caller skip this break and retry on a later heartbeat rather than air
        # an impersonating voice.
        retried = True
        remove_partial_output(output_path)
        ok, retry_failure = run_kokoro_with_timeout(
            text,
            voice,
            output_path,
            speed,
            KOKORO_RETRY_TIMEOUT_SECONDS,
        )
        if not ok:
            remove_partial_output(output_path)
            raise RuntimeError(
                f"Kokoro voice {voice} failed ({first_failure}); "
                f"same-voice retry failed ({retry_failure})"
            )

    print(json.dumps({
        "status": "ok",
        "output": output_path,
        "engine": "kokoro",
        "voice": voice,
        "retried": retried,
        "first_failure": first_failure if retried else None,
    }))


if __name__ == "__main__":
    main()
