// Place names for people (same rules as App\Domain\Passenger\PlaceLabel): drop postcodes and the country, keep the
// name and the area it is in. "Sandton City, Rivonia Road, Sandhurst, Sandton, 2196, South Africa" becomes
// {name: "Sandton City", detail: "Rivonia Road, Sandhurst", short: "Sandton City, Rivonia Road"}.

const COUNTRIES = new Set(['south africa', 'suid-afrika', 'mzansi']);

export function splitPlace(label) {
    const text = String(label || '').trim();
    let parts = text
        .split(',')
        .map((p) => p.trim())
        .filter((p) => p !== '' && !/^\d{4}$/.test(p) && !COUNTRIES.has(p.toLowerCase()));
    if (!parts.length) return { name: text, detail: '', short: text };
    if (parts.length > 1 && /^\d+[A-Za-z]?$/.test(parts[0])) parts = [`${parts[0]} ${parts[1]}`, ...parts.slice(2)];
    const [name, ...rest] = parts;
    return { name, detail: rest.slice(0, 2).join(', '), short: rest.length ? `${name}, ${rest[0]}` : name };
}

export const shortPlace = (label) => splitPlace(label).short;
