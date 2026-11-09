import type { ComponentPropsWithoutRef } from 'react';
import { fieldClass } from '../lib/funeral';
import { phoneCharacters, validPhone } from '../lib/phone';

type Props = Omit<
    ComponentPropsWithoutRef<'input'>,
    'type' | 'onChange' | 'value'
> & {
    value: string;
    onValueChange: (value: string) => void;
    validatePhone?: (value: string) => boolean;
};

export default function PhoneInput({
    value,
    onValueChange,
    validatePhone = validPhone,
    ...input
}: Props) {
    return (
        <input
            {...input}
            className={fieldClass}
            type="tel"
            inputMode="tel"
            maxLength={40}
            value={value}
            onChange={(event) => {
                const field = event.currentTarget;
                if (!phoneCharacters(field.value)) return;
                field.setCustomValidity(
                    field.value && !validatePhone(field.value)
                        ? 'Masukkan nomor telepon yang valid, misalnya 081234567890.'
                        : '',
                );
                onValueChange(field.value);
            }}
        />
    );
}
