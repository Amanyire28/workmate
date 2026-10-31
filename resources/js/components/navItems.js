/** True when `path` equals or sits under one of the `prefixes` ('/' matches only itself). */
export function navIsActive(path, prefixes) {
    return prefixes.some(p => (p === '/' ? path === '/' : path === p || path.startsWith(p + '/')));
}
