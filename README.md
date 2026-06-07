# 📊 Bitácoras Automatizadas — Comandos de Consola

[![Laravel Zero](https://img.shields.io/badge/Laravel%20Zero-F55247?style=for-the-badge&logo=laravel&logoColor=white)](https://laravel-zero.com/)
[![PHP Version](https://img.shields.io/badge/PHP-8.2%2B-777BB4?style=for-the-badge&logo=php&logoColor=white)](https://php.net)
[![Environment](https://img.shields.io/badge/Development-Laragon%20%7C%20Windows-007ACC?style=for-the-badge&logo=windows&logoColor=white)](https://laragon.org/)

Una potente herramienta de consola CLI desarrollada sobre **Laravel Zero** para la automatización, auditoría fiscal y ordenamiento de comprobantes digitales (CFDI 4.0) e integraciones con plataformas financieras.

---

## 📂 Estructura de Almacenamiento Local

El sistema trabaja de forma estricta bajo una arquitectura privada estructurada por cliente y segmentada por carpetas mensuales independientes.

```text
storage/app/private/CLIENTES/
└── [NOMBRE_CLIENTE_MAYÚSCULAS]/
    ├── CREDENCIALES/      # 🔒 Ignorado en el repositorio por seguridad
    │   └── config.json    # Parámetros de configuración del cliente
    ├── xml/
    │   └── MAYO-2026/     # Estructura mensual estricta
    ├── pdf/
    │   └── MAYO-2026/     # Estructura mensual estricta
    └── SOLICITUDES/