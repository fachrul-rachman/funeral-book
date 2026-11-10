import { Eye, EyeOff } from 'lucide-react';
import { useId, useState } from 'react';
import type { ComponentPropsWithoutRef } from 'react';
import { fieldClass, invalidFieldClass } from '../lib/funeral';

type Props = Omit<ComponentPropsWithoutRef<'input'>, 'type'> & {
    label: string;
    invalid?: boolean;
};

export default function PasswordInput({
    label,
    invalid = false,
    ...input
}: Props) {
    const [visible, setVisible] = useState(false);
    const id = useId();

    return (
        <div>
            <label className="block font-medium" htmlFor={id}>
                {label}
            </label>
            <div className="relative mt-1">
                <input
                    {...input}
                    id={id}
                    type={visible ? 'text' : 'password'}
                    aria-invalid={invalid || undefined}
                    className={
                        (invalid ? invalidFieldClass : fieldClass) + ' pr-14'
                    }
                />
                <button
                    type="button"
                    aria-label={
                        visible
                            ? 'Sembunyikan kata sandi'
                            : 'Tampilkan kata sandi'
                    }
                    aria-controls={id}
                    onClick={() => setVisible(!visible)}
                    className="absolute inset-y-0 right-0 flex w-12 items-center justify-center rounded-r-md hover:bg-navy/5"
                >
                    {visible ? (
                        <EyeOff className="size-5" aria-hidden="true" />
                    ) : (
                        <Eye className="size-5" aria-hidden="true" />
                    )}
                </button>
            </div>
        </div>
    );
}
