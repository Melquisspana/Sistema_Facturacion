import defaultTheme from 'tailwindcss/defaultTheme';
import forms from '@tailwindcss/forms';

/** @type {import('tailwindcss').Config} */
export default {
    // Clase manual (no 'media'): el toggle claro/oscuro y el valor guardado en
    // localStorage controlan la clase `dark` en <html> (ver resources/js/app.js).
    darkMode: 'class',

    content: [
        './vendor/laravel/framework/src/Illuminate/Pagination/resources/views/*.blade.php',
        './storage/framework/views/*.php',
        './resources/views/**/*.blade.php',
    ],

    theme: {
        extend: {
            fontFamily: {
                sans: ['Figtree', ...defaultTheme.fontFamily.sans],
            },
            // Paleta del tema oscuro (grafito cálido, no negro puro). Se usa directamente
            // en clases dark: del layout/sidebar/componentes compartidos; el resto de las
            // pantallas se retematiza vía los overrides globales en app.css (ver ese
            // archivo para el porqué: no se toca cada vista una por una).
            colors: {
                ink: {
                    950: '#131417',
                    900: '#1a1b20',
                    800: '#202227',
                    700: '#2a2c33',
                    600: '#34363f',
                    500: '#4a4d59',
                },
                paper: {
                    100: '#e9eaee',
                    300: '#a3a6b1',
                    500: '#6f7280',
                },
            },
        },
    },

    plugins: [forms],
};
