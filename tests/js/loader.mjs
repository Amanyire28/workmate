const MAP = {
    '@/api/client.js': new URL('./stubs/api.mjs', import.meta.url).href,
    '@/stores/auth.js': new URL('./stubs/auth.mjs', import.meta.url).href,
};

export async function resolve(specifier, context, next) {
    if (MAP[specifier]) return { url: MAP[specifier], shortCircuit: true };

    return next(specifier, context);
}
