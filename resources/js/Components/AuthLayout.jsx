import React from 'react';
import { Text } from '@radix-ui/themes';

import logo from '../../../public/assets/images/logo.png';

/*
 * Stand-alone auth screens (Cyber page_login / page_register): centred
 * 360px column on the Cyber cover, mark → uppercase title → muted subtitle.
 * Styles: resources/css/design/cyber/shell.css (.dl-auth*).
 */
const AuthLayout = ({ children, title, subtitle, mark, style }) => (
    <main className="dl-auth" style={style}>
        <div className="dl-auth__panel">
            <div className="dl-auth__head">
                <div className="dl-auth__mark" aria-hidden={mark ? 'true' : undefined}>
                    {mark ?? (
                        <img src={logo} alt="DBEDC" onError={(e) => { e.currentTarget.style.display = 'none'; }} />
                    )}
                </div>
                <h1 className="dl-auth__title">{title}</h1>
                {subtitle && <p className="dl-auth__subtitle">{subtitle}</p>}
            </div>
            {children}
        </div>
    </main>
);

/* Label above the control, optional link on the right (e.g. "Forgot password?"). */
export function AuthField({ id, label, required = false, aside, error, children }) {
    return (
        <div className="dl-auth__field">
            <div className="dl-auth__label-row">
                <label htmlFor={id} className="dl-auth__label">
                    {label}
                    {required && <span className="dl-auth__required" aria-hidden="true">*</span>}
                </label>
                {aside}
            </div>
            {children}
            {error && <Text size="1" color="red" id={`${id}-error`} role="alert">{error}</Text>}
        </div>
    );
}

export default AuthLayout;
