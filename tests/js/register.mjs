// Lets Node load the app's own store modules for unit tests: maps the app's "@/…" imports to small stand-ins.
// Run:  node --import ./tests/js/register.mjs --test --test-timeout=20000 "tests/js/*.test.mjs"
import { register } from 'node:module';

register('./loader.mjs', import.meta.url);
