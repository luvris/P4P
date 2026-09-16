export const formatCurrency = (value) => {
    const n = Number(value);
    if (value === null || value === undefined || value === '' || Number.isNaN(n)) {
        return '-';
    }
    return n.toLocaleString('th-TH', {
        minimumFractionDigits: 2,
        maximumFractionDigits: 2,
    });
};

export const formatDate = (value) => {
    if (!value) return '-';
    const d = new Date(value);
    if (Number.isNaN(d.getTime())) return '-';
    return d.toLocaleDateString('th-TH', {
        day: '2-digit',
        month: 'short',
        year: 'numeric',
    });
};