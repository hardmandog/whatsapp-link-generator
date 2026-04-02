# WhatsApp Link Generator

Plugin de WordPress que genera enlaces rastreables de WhatsApp con el título de la página y registra estadísticas de clics en el panel de administración.

## Características

- **Shortcode `[enlace_whatsapp]`** — genera una URL rastreable que redirige a WhatsApp con el título de la página pre-cargado en el mensaje
- **Estadísticas de clics** — tabla en el admin con total, últimos 30 días, clics de hoy, primer y último clic por página
- **Filtro por rango de fechas** en la vista de estadísticas
- **Exportar CSV** — descarga el historial completo o filtrado con BOM para compatibilidad con Excel
- **Rate limiting por cookie** — ignora clics repetidos del mismo usuario en ventana de 30 minutos
- **INSERT atómico** — evita race conditions en entornos con alta concurrencia
- **Borrar historial por página** individual desde el panel de estadísticas
- **Número y mensaje configurables** desde Ajustes → WhatsApp Link

## Requisitos

- WordPress 5.8+
- PHP 7.4+

## Instalación

1. Sube la carpeta `whatsapp-link-generator` a `/wp-content/plugins/`
2. Activa el plugin desde **Plugins → Plugins instalados**
3. Ve a **WhatsApp Link → Configuración** y ajusta el número y el mensaje por defecto

## Uso

### Shortcode

```
[enlace_whatsapp]
```

Genera una URL del tipo `https://tusitio.com/?wlg_click=1`. Al hacer clic:

1. Registra el clic en la base de datos (respetando rate limit de 30 min por usuario/página)
2. Redirige a `https://wa.me/{numero}?text=Mensaje+*Título de la página*`

> **Importante:** Si tu constructor de páginas (como Elementor) no interpreta shortcodes dentro de atributos `href`, usa el shortcode para obtener la URL y pégala manualmente en el botón.

### Panel de estadísticas

**WhatsApp Link → Estadísticas**

| Columna | Descripción |
|---------|-------------|
| Página | Título del post/página |
| Total clics | Acumulado histórico |
| Últimos 30 días | Clics en el período reciente |
| Hoy | Clics del día actual (resaltados en verde) |
| Primer / Último clic | Fechas extremas del registro |

## Base de datos

El plugin crea la tabla `{prefix}_whatsapp_clicks` al activarse:

```sql
CREATE TABLE {prefix}_whatsapp_clicks (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    page_title VARCHAR(255) NOT NULL,
    click_date DATE NOT NULL,
    click_count INT NOT NULL DEFAULT 1,
    UNIQUE KEY unique_click (page_title(191), click_date)
);
```

## Opciones de WordPress

| Opción | Por defecto |
|--------|-------------|
| `whatsapp_number` | _(vacío — configurar en Ajustes)_ |
| `whatsapp_message` | `Hola, deseo una cotización sobre: ` |

## Licencia

GPLv2 or later — ver [https://www.gnu.org/licenses/gpl-2.0.html](https://www.gnu.org/licenses/gpl-2.0.html)
