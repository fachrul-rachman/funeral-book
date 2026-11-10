import type { ComponentPropsWithoutRef } from 'react';
import { fieldClass, invalidFieldClass } from '../lib/funeral';
import { phoneCharacters, validPhone } from '../lib/phone';

type Props = Omit<
    ComponentPropsWithoutRef<'input'>,
    'type' | 'onChange' | 'value'
> & {
    value: string;
    onValueChange: (value: string) => void;
    validatePhone?: (value: string) => boolean;
    invalid?: boolean;
};

export default function PhoneInput({
    value,
    onValueChange,
    validatePhone = validPhone,
    invalid = false,
    ...input
}: Props) {
    return (
        <input
            {...input}
            aria-invalid={invalid || undefined}
            className={invalid ? invalidFieldClass : fieldClass}
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
