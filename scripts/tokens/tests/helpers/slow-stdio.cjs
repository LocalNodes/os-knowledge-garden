// Preload (node --require): makes stdout and stderr behave the way a pipe does when its reader is slow.
// Node queues a write that cannot complete at once and finishes it later; a process that calls process.exit()
// while writes are queued loses them. This defers every write by a timer so the loss is deterministic.
for (const stream of [process.stdout, process.stderr]) {
  const write = stream.write.bind(stream);
  stream.write = (chunk, encoding, cb) => {
    setTimeout(() => write(chunk, encoding, cb), 5);
    return true;
  };
}
