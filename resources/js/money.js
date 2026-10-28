export function formatCurrency(centavos) {
    const absolute = Math.abs(centavos);
    const whole = String(Math.trunc(absolute / 100)).replace(/\B(?=(\d{3})+(?!\d))/g, ',');
    const fraction = String(absolute % 100).padStart(2, '0');
    return '₱' + (centavos < 0 ? '-' : '') + whole + '.' + fraction;
}