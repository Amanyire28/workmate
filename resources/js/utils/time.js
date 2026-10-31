/** "14:05" for today, "12 Oct" otherwise */
export function timeLabel(iso) {
    const d = new Date(iso);
    if (d.toDateString() === new Date().toDateString()) {
        return d.toLocaleTimeString('en-UG', { hour: '2-digit', minute: '2-digit' });
    }
    return d.toLocaleDateString('en-UG', { day: 'numeric', month: 'short' });
}
