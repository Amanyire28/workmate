/** @type {import('tailwindcss').Config} */
export default {
    content: [
        './resources/**/*.blade.php',
        './resources/**/*.js',
        './resources/**/*.vue',
    ],
    theme: {
        extend: {
            colors: {
                brand: {
                    50:  '#f0f9ff',
                    100: '#e0f2fe',
                    200: '#bae6fd',
                    300: '#7dd3fc',
                    400: '#38bdf8',
                    500: '#0ea5e9',
                    600: '#0284c7',
                    700: '#0369a1',
                    800: '#075985',
                    900: '#1e3a8a',
                },
            },
            fontFamily: {
                sans: ['Inter', 'system-ui', 'sans-serif'],
            },
            borderRadius: {
                'xl': '0.875rem',
                '2xl': '1.25rem',
            },
            boxShadow: {
                'card': '0 1px 4px 0 rgba(14, 165, 233, 0.08), 0 1px 2px 0 rgba(0,0,0,0.04)',
                'card-hover': '0 4px 16px 0 rgba(14, 165, 233, 0.15), 0 2px 4px 0 rgba(0,0,0,0.06)',
            },
        },
    },
    plugins: [
        require('@tailwindcss/forms'),
    ],
};
