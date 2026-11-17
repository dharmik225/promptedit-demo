/**
 * Formats an exact decimal string from the API as US dollars. The value is
 * never converted to a JavaScript number, so no precision is lost: trailing
 * zeros are trimmed down to two decimal places.
 */
export function formatUsd(amount: string | null | undefined): string {
    if (amount === null || amount === undefined || amount === '') {
        return '—';
    }

    const [whole, fraction = ''] = amount.split('.');
    const significantFraction = fraction.replace(/0+$/, '').padEnd(2, '0');

    return `$${whole}.${significantFraction}`;
}

export function formatTokens(tokens: number | null | undefined): string {
    if (tokens === null || tokens === undefined) {
        return '—';
    }

    return new Intl.NumberFormat().format(tokens);
}

export function formatDateTime(isoDate: string | null | undefined): string {
    if (!isoDate) {
        return '—';
    }

    return new Intl.DateTimeFormat(undefined, { dateStyle: 'medium', timeStyle: 'short' }).format(new Date(isoDate));
}

export function aspectRatioFromSize(size: string): string {
    const [width, height] = size.split('x');

    return width && height ? `${width} / ${height}` : '1 / 1';
}

export function promptCharacterCount(prompt: string): number {
    return [...prompt.trim()].length;
}
