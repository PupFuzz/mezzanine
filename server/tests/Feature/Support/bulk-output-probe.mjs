// card#12946 — a probe that prints a large answer, for TheProbeRigReadsALargeAnswerInLinearTimeTest.
// Reads its payload ({"stdout_bytes": n, "stderr_bytes": m}) from stdin as every probe does, then
// writes n bytes to stdout and m to stderr in 64 KiB writes — the shape of a long floor run's JSON.

const chunks = [];

for await (const chunk of process.stdin) {
    chunks.push(chunk);
}

const payload = JSON.parse(Buffer.concat(chunks).toString('utf8'));

const pour = async (stream, bytes, fill) => {
    const block = Buffer.alloc(64 * 1024, fill);

    for (let left = bytes; left > 0; left -= block.length) {
        const part = left >= block.length ? block : block.subarray(0, left);

        if (!stream.write(part)) {
            await new Promise((resolve) => stream.once('drain', resolve));
        }
    }
};

await Promise.all([
    pour(process.stdout, payload.stdout_bytes, 'o'),
    pour(process.stderr, payload.stderr_bytes, 'e'),
]);
