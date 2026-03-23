import type { SVGAttributes } from 'react';

export default function AppLogoIcon(props: SVGAttributes<SVGElement>) {
    return (
        <svg {...props} viewBox="0 0 32 32" xmlns="http://www.w3.org/2000/svg">
            <circle cx="16" cy="16" r="14" fill="none" stroke="currentColor" strokeWidth="2.5" />
            <circle cx="16" cy="16" r="6" fill="none" stroke="currentColor" strokeWidth="2" />
            <line x1="16" y1="2" x2="16" y2="10" stroke="currentColor" strokeWidth="1.5" />
            <line x1="16" y1="22" x2="16" y2="30" stroke="currentColor" strokeWidth="1.5" />
            <line x1="2" y1="16" x2="10" y2="16" stroke="currentColor" strokeWidth="1.5" />
            <line x1="22" y1="16" x2="30" y2="16" stroke="currentColor" strokeWidth="1.5" />
        </svg>
    );
}
