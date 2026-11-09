export function validPhone(value: string) {
    const phone = value.replace(/[ ()-]+/g, '');
    const normalized = phone.startsWith('08')
        ? '+62' + phone.slice(1)
        : phone.startsWith('628')
          ? '+' + phone
          : phone;

    return /^\+[1-9][0-9]{7,14}$/.test(normalized);
}

export function validApplicantPhone(value: string) {
    const phone = value.replace(/[ ()-]+/g, '');
    const digits = phone.replace(/^\+/, '');
    return (
        /^(?:08|\+?62)[0-9]+$/.test(phone) &&
        digits.length >= 10 &&
        digits.length <= 13
    );
}

export function applicantPhoneInput(value: string) {
    return value.startsWith('+628') ? '0' + value.slice(3) : value;
}

export function phoneCharacters(value: string) {
    return /^\+?[0-9 ()-]*$/.test(value);
}
