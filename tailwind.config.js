/** @type {import('tailwindcss').Config} */
module.exports = {
    darkMode: 'class',
    content: [
        './resources/views/**/*.blade.php',
    ],
    theme: {
        extend: {
            colors: {
                slate: {
                    850: '#172033',
                }
            }
        }
    },
    plugins: [],
}
