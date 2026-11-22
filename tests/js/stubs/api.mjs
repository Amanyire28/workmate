// A fake server the tests control: each call waits for the test to answer it, so the order of answers is exact.
export const server = { calls: [], waiting: [] };

export function api(path) {
    return new Promise((resolve, reject) => {
        server.calls.push(path);
        server.waiting.push({ path, resolve, reject });
    });
}

/** Answer the oldest unanswered request for $path */
export function answer(path, data) {
    const i = server.waiting.findIndex(w => w.path === path);
    if (i < 0) throw new Error(`no request waiting for ${path}`);
    const [w] = server.waiting.splice(i, 1);
    w.resolve({ data });
}

/** Answer the NEWEST unanswered request for $path */
export function answerLast(path, data) {
    const i = server.waiting.map(w => w.path).lastIndexOf(path);
    if (i < 0) throw new Error(`no request waiting for ${path}`);
    const [w] = server.waiting.splice(i, 1);
    w.resolve({ data });
}

export function fail(path, message) {
    const i = server.waiting.findIndex(w => w.path === path);
    const [w] = server.waiting.splice(i, 1);
    w.reject(new Error(message));
}

export function reset() {
    server.calls = [];
    server.waiting = [];
}
