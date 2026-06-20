# modxMCP

Управление сайтом **MODX Revolution 2.x** через ИИ по протоколу MCP.

## Установка на сайт

1. Скачать `modxmcp-*.transport.zip` из **Releases** и установить: **Дополнения → Установщик → Загрузить пакет**.
2. В **Дополнения → modxMCP** включить компонент и сгенерировать токен.

## Подключение

В `.mcp.json` проекта указать адрес сайта и токен, затем переподключить MCP (`/mcp`):

```json
{
  "mcpServers": {
    "modx": {
      "command": "npx",
      "args": ["-y", "github:dampilov94/mcp-component"],
      "env": {
        "MODX_MCP_SITE_URL": "http://САЙТ/assets/components/modxmcp/api.php",
        "MODX_MCP_TOKEN": "ваш-токен"
      }
    }
  }
}
```
