<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="dark">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <title>CachyOS Store</title>
        <link rel="preconnect" href="https://fonts.googleapis.com">
        <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
        <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
        <script src="https://cdn.tailwindcss.com"></script>
        <script>
            tailwind.config = {
                darkMode: 'class',
                theme: {
                    extend: {
                        colors: {
                            cachy: '#2db531',
                            border: "hsl(240 3.7% 15.9%)",
                            input: "hsl(240 3.7% 15.9%)",
                            ring: "hsl(240 4.9% 83.9%)",
                            background: "hsl(240 10% 3.9%)",
                            foreground: "hsl(0 0% 98%)",
                            primary: {
                                DEFAULT: "hsl(0 0% 98%)",
                                foreground: "hsl(240 5.9% 10%)",
                            },
                            secondary: {
                                DEFAULT: "hsl(240 3.7% 15.9%)",
                                foreground: "hsl(0 0% 98%)",
                            },
                            destructive: {
                                DEFAULT: "hsl(0 62.8% 30.6%)",
                                foreground: "hsl(0 0% 98%)",
                            },
                            muted: {
                                DEFAULT: "hsl(240 3.7% 15.9%)",
                                foreground: "hsl(240 5% 64.9%)",
                            },
                            accent: {
                                DEFAULT: "hsl(240 3.7% 15.9%)",
                                foreground: "hsl(0 0% 98%)",
                            },
                            popover: {
                                DEFAULT: "hsl(240 10% 3.9%)",
                                foreground: "hsl(0 0% 98%)",
                            },
                            card: {
                                DEFAULT: "hsl(240 10% 3.9%)",
                                foreground: "hsl(0 0% 98%)",
                            },
                        },
                        borderRadius: {
                            xl: "18px",
                            lg: "14px",
                            md: "10px",
                            sm: "6px",
                        },
                        fontFamily: {
                            sans: ['Inter', 'sans-serif'],
                        },
                    }
                }
            }
        </script>
        <style>
            ::-webkit-scrollbar {
                width: 6px;
            }
            ::-webkit-scrollbar-track {
                background: hsl(240 10% 3.9%);
            }
            ::-webkit-scrollbar-thumb {
                background: hsl(240 3.7% 15.9%);
                border-radius: 10px;
            }
            ::-webkit-scrollbar-thumb:hover {
                background: #2db531;
            }
            html, body {
                height: 100%;
                margin: 0;
                padding: 0;
                overflow: hidden;
            }
            body {
                font-family: 'Inter', sans-serif;
                -webkit-font-smoothing: antialiased;
                background-color: hsl(240 10% 3.9%);
                color: hsl(0 0% 98%);
            }
            
            /* Region drag control */
            header { -webkit-app-region: drag; }
            button, input, a, .no-drag { -webkit-app-region: no-drag; }

            /* Pacman Animation (Shadcn Green version) */
            .pacman-container {
                position: relative;
                width: 140px;
                height: 50px;
                display: flex;
                align-items: center;
                justify-content: flex-start;
            }
            .pacman {
                position: relative;
                z-index: 2;
                width: 40px;
                height: 40px;
                background: #2db531;
                border-radius: 50%;
                clip-path: polygon(100% 0%, 100% 100%, 0% 100%, 0% 0%);
                animation: eat 0.3s infinite ease-in-out alternate;
            }
            .pacman::after {
                content: '';
                position: absolute;
                top: 8px;
                left: 20px;
                width: 4px;
                height: 4px;
                background: #000;
                border-radius: 50%;
            }
            @keyframes eat {
                0% { clip-path: polygon(100% 50%, 100% 100%, 0% 100%, 0% 0%, 100% 0%); }
                100% { clip-path: polygon(50% 50%, 100% 100%, 0% 100%, 0% 0%, 100% 0%); }
            }
            .dot {
                width: 6px;
                height: 6px;
                background: hsl(240 3.7% 15.9%);
                border-radius: 50%;
                margin-left: 20px;
                animation: dots 0.8s infinite linear;
            }
            @keyframes dots {
                0% { transform: translateX(100px); opacity: 0; }
                50% { opacity: 1; }
                100% { transform: translateX(-20px); opacity: 0; }
            }

            /* Spinner Minimalist */
            .spinner {
                width: 16px;
                height: 16px;
                border: 2px solid hsl(240 3.7% 15.9%);
                border-radius: 50%;
                border-top-color: #2db531;
                animation: spin 0.8s linear infinite;
            }
            @keyframes spin {
                to { transform: rotate(360deg); }
            }

            /* Progress Bar Minimalist */
            .progress-bar-loading {
                position: absolute;
                bottom: 0;
                left: 0;
                height: 2px;
                background: #2db531;
                animation: progress-load 2s infinite ease-in-out;
            }
            @keyframes progress-load {
                0% { width: 0; left: 0; }
                50% { width: 100%; left: 0; }
                100% { width: 0; left: 100%; }
            }
        </style>
    </head>
    <body class="antialiased bg-background text-foreground min-h-screen overflow-hidden">
        <livewire:store />
    </body>
</html>
