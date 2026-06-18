#!/usr/bin/env node

const { Server } = require("@modelcontextprotocol/sdk/server/index.js");
const {
  StdioServerTransport,
} = require("@modelcontextprotocol/sdk/server/stdio.js");
const {
  CallToolRequestSchema,
  ListToolsRequestSchema,
} = require("@modelcontextprotocol/sdk/types.js");
const axios = require("axios");
const fs = require("fs");
const path = require("path");

function loadLocalEnv() {
  const candidates = [
    path.resolve(process.cwd(), ".env"),
    path.resolve(__dirname, "..", ".env"),
  ];
  const loaded = new Set();

  for (const filePath of candidates) {
    if (loaded.has(filePath) || !fs.existsSync(filePath)) {
      continue;
    }
    loaded.add(filePath);

    const lines = fs.readFileSync(filePath, "utf8").split(/\r?\n/);
    for (const line of lines) {
      const trimmed = line.trim();
      if (!trimmed || trimmed.startsWith("#")) {
        continue;
      }

      const separatorIndex = trimmed.indexOf("=");
      if (separatorIndex === -1) {
        continue;
      }

      const key = trimmed.slice(0, separatorIndex).trim();
      const value = trimmed.slice(separatorIndex + 1).trim();
      if (key && process.env[key] === undefined) {
        process.env[key] = value.replace(/^["']|["']$/g, "");
      }
    }
  }
}

loadLocalEnv();

const MODX_SITE_URL = process.env.MODX_MCP_SITE_URL;
const API_TOKEN = process.env.MODX_MCP_TOKEN;

if (!MODX_SITE_URL) {
  throw new Error(
    "MODX_MCP_SITE_URL is required, e.g. https://your-site.com/assets/components/modxmcp/api.php",
  );
}
if (!API_TOKEN) {
  throw new Error(
    "MODX_MCP_TOKEN is required (the modxmcp.api_token system setting value from the target MODX site).",
  );
}

const server = new Server(
  { name: "modx-codex-server", version: "7.1.0" },
  { capabilities: { tools: {} } },
);

async function modxApiRequest(payload) {
  try {
    const response = await axios.post(MODX_SITE_URL, payload, {
      headers: {
        "X-MCP-Token": API_TOKEN,
        "Content-Type": "application/json; charset=utf-8",
      },
    });
    return response.data;
  } catch (error) {
    if (error.response) {
      throw new Error(`MODX Error: ${JSON.stringify(error.response.data)}`);
    }
    throw new Error(`Network Error: ${error.message}`);
  }
}

const ELEMENT_TYPES = [
  "chunk",
  "snippet",
  "template",
  "resource",
  "tv",
  "category",
  "plugin",
];

const VERSIONX_TYPES = ["resource", "chunk", "snippet", "template", "plugin", "tv"];
const VIRTUALPAGE_HANDLER_TYPES = [
  "resource",
  "snippet",
  "chunk",
  "dynamic_resource",
  "template",
  "0",
  "1",
  "2",
  "3",
];

const toolDefinitions = [
  {
    name: "modx_list_elements",
    description: "List MODX elements of a type (id + name). Supports an optional name filter and pagination.",
    inputSchema: {
      type: "object",
      properties: {
        type: { type: "string", enum: ELEMENT_TYPES },
        query: { type: "string", description: "Filter elements by name/content (passed to the getlist processor)." },
        limit: { type: "number", description: "Max results (0 = all, the default)." },
        start: { type: "number", description: "Offset for pagination." },
      },
      required: ["type"],
    },
  },
  {
    name: "modx_make_static",
    description:
      "Convert a chunk/snippet/template/plugin to a static file under core/elements/ (writes the file, sets static=1 + source=Filesystem). One element via {type,id}, or a batch via {items:[{type,id}]}. Edit the resulting files directly afterwards. (See also the modxmcp.auto_static setting to do this automatically on every create/update.)",
    inputSchema: {
      type: "object",
      properties: {
        type: { type: "string", enum: ["chunk", "snippet", "template", "plugin"] },
        id: { type: "number" },
        items: {
          type: "array",
          items: {
            type: "object",
            properties: {
              type: { type: "string", enum: ["chunk", "snippet", "template", "plugin"] },
              id: { type: "number" },
            },
          },
          description: "Batch: list of {type,id} to convert.",
        },
      },
    },
  },
  {
    name: "modx_get_element",
    description: "Прочитать существующий элемент MODX.",
    inputSchema: {
      type: "object",
      properties: {
        type: { type: "string", enum: ELEMENT_TYPES },
        name: { type: "string" },
        id: { type: "number" },
      },
      required: ["type"],
    },
  },
  {
    name: "modx_update_element",
    description: "Обновить элемент MODX.",
    inputSchema: {
      type: "object",
      properties: {
        type: { type: "string", enum: ELEMENT_TYPES },
        name: { type: "string" },
        id: { type: "number" },
        content: { type: "string" },
        parent: { type: "number" },
        template: { type: "number" },
        published: { type: "number" },
        alias: { type: "string" },
        class_key: { type: "string" },
        context_key: { type: "string" },
        isfolder: { type: "number" },
        hidemenu: { type: "number" },
        introtext: { type: "string" },
        menutitle: { type: "string" },
        article: { type: "string" },
        price: { type: "number" },
        old_price: { type: "number" },
        weight: { type: "number" },
        remains: { type: "number" },
        vendor: { type: "number" },
        made_in: { type: "string" },
        new: { type: "number" },
        popular: { type: "number" },
        favorite: { type: "number" },
        tags: { type: "string" },
        color: { type: "string" },
        size: { type: "string" },
        events: { type: "array", items: { type: "string" } },
        caption: { type: "string" },
        field_type: { type: "string" },
        templates: { type: "array", items: { type: "number" } },
        media_source: { type: "number" },
        input_properties: { type: "object" },
        category: { type: "number" },
      },
      required: ["type"],
    },
  },
  {
    name: "modx_create_element",
    description: "Создать новый элемент MODX.",
    inputSchema: {
      type: "object",
      properties: {
        type: { type: "string", enum: ELEMENT_TYPES },
        name: { type: "string" },
        content: { type: "string" },
        parent: { type: "number" },
        template: { type: "number" },
        published: { type: "number" },
        alias: { type: "string" },
        class_key: { type: "string" },
        context_key: { type: "string" },
        isfolder: { type: "number" },
        hidemenu: { type: "number" },
        introtext: { type: "string" },
        menutitle: { type: "string" },
        article: { type: "string" },
        price: { type: "number" },
        old_price: { type: "number" },
        weight: { type: "number" },
        remains: { type: "number" },
        vendor: { type: "number" },
        made_in: { type: "string" },
        new: { type: "number" },
        popular: { type: "number" },
        favorite: { type: "number" },
        tags: { type: "string" },
        color: { type: "string" },
        size: { type: "string" },
        events: { type: "array", items: { type: "string" } },
        caption: { type: "string" },
        field_type: { type: "string" },
        templates: { type: "array", items: { type: "number" } },
        media_source: { type: "number" },
        input_properties: { type: "object" },
        category: { type: "number" },
      },
      required: ["type", "name"],
    },
  },
  {
    name: "modx_delete_element",
    description: "Удалить элемент MODX.",
    inputSchema: {
      type: "object",
      properties: {
        type: { type: "string", enum: ELEMENT_TYPES },
        name: { type: "string" },
        id: { type: "number" },
      },
      required: ["type"],
    },
  },
  {
    name: "modx_list_system_settings",
    description: "Получить список системных настроек MODX.",
    inputSchema: {
      type: "object",
      properties: {
        namespace: { type: "string" },
        area: { type: "string" },
      },
    },
  },
  {
    name: "modx_get_system_setting",
    description: "Получить одну системную настройку MODX.",
    inputSchema: {
      type: "object",
      properties: {
        key: { type: "string" },
        id: { type: "number" },
      },
    },
  },
  {
    name: "modx_create_system_setting",
    description: "Создать системную настройку MODX.",
    inputSchema: {
      type: "object",
      properties: {
        key: { type: "string" },
        value: { type: "string" },
        xtype: { type: "string" },
        namespace: { type: "string" },
        area: { type: "string" },
      },
      required: ["key"],
    },
  },
  {
    name: "modx_update_system_setting",
    description: "Обновить системную настройку MODX.",
    inputSchema: {
      type: "object",
      properties: {
        key: { type: "string" },
        id: { type: "number" },
        value: { type: "string" },
        xtype: { type: "string" },
        namespace: { type: "string" },
        area: { type: "string" },
      },
    },
  },
  {
    name: "modx_delete_system_setting",
    description: "Удалить системную настройку MODX.",
    inputSchema: {
      type: "object",
      properties: {
        key: { type: "string" },
        id: { type: "number" },
      },
    },
  },
  {
    name: "modx_list_media_sources",
    description: "Получить список media sources MODX.",
    inputSchema: {
      type: "object",
      properties: {},
    },
  },
  {
    name: "modx_get_media_source",
    description: "Получить один media source MODX.",
    inputSchema: {
      type: "object",
      properties: {
        id: { type: "number" },
        name: { type: "string" },
      },
    },
  },
  {
    name: "modx_list_media_source_files",
    description: "Получить список файлов и папок внутри media source.",
    inputSchema: {
      type: "object",
      properties: {
        id: { type: "number" },
        name: { type: "string" },
        path: { type: "string" },
      },
    },
  },
  {
    name: "modx_read_media_source_file",
    description: "Прочитать файл внутри media source.",
    inputSchema: {
      type: "object",
      properties: {
        id: { type: "number" },
        name: { type: "string" },
        path: { type: "string" },
      },
      required: ["path"],
    },
  },
    {
      name: "modx_list_installed_components",
      description: "List installed MODX components from core/components and assets/components.",
      inputSchema: {
        type: "object",
        properties: {},
      },
    },
    {
      name: "modx_get_component_files",
      description: "List files and folders inside an installed MODX component.",
      inputSchema: {
        type: "object",
        properties: {
          name: { type: "string" },
          scope: { type: "string", enum: ["core", "assets", "all"] },
          path: { type: "string" },
        },
        required: ["name"],
      },
    },
    {
      name: "modx_read_component_file",
      description: "Read a file from an installed MODX component.",
      inputSchema: {
        type: "object",
        properties: {
          name: { type: "string" },
          scope: { type: "string", enum: ["core", "assets", "all"] },
          path: { type: "string" },
        },
        required: ["name", "path"],
      },
    },
    {
    name: "modx_get_resource_tvs",
    description: "Получить TV-значения ресурса.",
    inputSchema: {
      type: "object",
      properties: {
        resource_id: { type: "number" },
      },
      required: ["resource_id"],
    },
  },
  {
    name: "modx_update_resource_tvs",
    description: "Обновить TV-значения ресурса.",
    inputSchema: {
      type: "object",
      properties: {
        resource_id: { type: "number" },
        tvs: { type: "object" },
      },
      required: ["resource_id", "tvs"],
    },
  },
  {
    name: "modx_versionx_list_versions",
    description:
      "List VersionX versions for a MODX resource, chunk, snippet, template, plugin, or TV.",
    inputSchema: {
      type: "object",
      properties: {
        type: { type: "string", enum: VERSIONX_TYPES },
        content_id: { type: "number" },
        limit: { type: "number" },
      },
      required: ["type", "content_id"],
    },
  },
  {
    name: "modx_versionx_get_version",
    description:
      "Read one VersionX version payload before deciding whether to revert to it.",
    inputSchema: {
      type: "object",
      properties: {
        type: { type: "string", enum: VERSIONX_TYPES },
        content_id: { type: "number" },
        version_id: { type: "number" },
      },
      required: ["type", "content_id", "version_id"],
    },
  },
  {
    name: "modx_versionx_revert_version",
    description:
      "Revert a MODX object to a specific VersionX version. This changes live MODX data and requires confirm=true.",
    inputSchema: {
      type: "object",
      properties: {
        type: { type: "string", enum: VERSIONX_TYPES },
        content_id: { type: "number" },
        version_id: { type: "number" },
        confirm: { type: "boolean" },
      },
      required: ["type", "content_id", "version_id", "confirm"],
    },
  },
  {
    name: "modx_ms2_list_option_types",
    description: "List available miniShop2 product option field types.",
    inputSchema: {
      type: "object",
      properties: {},
    },
  },
  {
    name: "modx_ms2_list_options",
    description: "List miniShop2 product options from the settings options tab.",
    inputSchema: {
      type: "object",
      properties: {
        query: { type: "string" },
        category: { type: "number" },
        modcategory: { type: "number" },
        limit: { type: "number" },
      },
    },
  },
  {
    name: "modx_ms2_get_option",
    description: "Read one miniShop2 product option by id.",
    inputSchema: {
      type: "object",
      properties: {
        id: { type: "number" },
      },
      required: ["id"],
    },
  },
  {
    name: "modx_ms2_create_option",
    description: "Create a miniShop2 product option in settings options.",
    inputSchema: {
      type: "object",
      properties: {
        key: { type: "string" },
        caption: { type: "string" },
        description: { type: "string" },
        measure_unit: { type: "string" },
        category: { type: "number" },
        type: { type: "string" },
        properties: { type: "object" },
        category_ids: { type: "array", items: { type: "number" } },
      },
      required: ["key", "caption", "type"],
    },
  },
  {
    name: "modx_ms2_update_option",
    description: "Update a miniShop2 product option in settings options.",
    inputSchema: {
      type: "object",
      properties: {
        id: { type: "number" },
        key: { type: "string" },
        caption: { type: "string" },
        description: { type: "string" },
        measure_unit: { type: "string" },
        category: { type: "number" },
        type: { type: "string" },
        properties: { type: "object" },
        category_ids: { type: "array", items: { type: "number" } },
      },
      required: ["id"],
    },
  },
  {
    name: "modx_ms2_assign_option_to_category",
    description: "Assign an existing miniShop2 option to an msCategory.",
    inputSchema: {
      type: "object",
      properties: {
        option_id: { type: "number" },
        category_id: { type: "number" },
      },
      required: ["option_id", "category_id"],
    },
  },
  {
    name: "modx_ms2_get_product_options",
    description: "Read miniShop2 option values assigned to a product.",
    inputSchema: {
      type: "object",
      properties: {
        product_id: { type: "number" },
      },
      required: ["product_id"],
    },
  },
  {
    name: "modx_ms2_update_product_options",
    description:
      "Create, update, or remove miniShop2 option values for a product. Use null value to remove an option value.",
    inputSchema: {
      type: "object",
      properties: {
        product_id: { type: "number" },
        options: {
          type: "object",
          additionalProperties: {
            anyOf: [
              { type: "string" },
              { type: "number" },
              { type: "boolean" },
              { type: "array", items: { type: "string" } },
              { type: "null" },
            ],
          },
        },
      },
      required: ["product_id", "options"],
    },
  },
  {
    name: "modx_virtualpage_list_events",
    description: "List VirtualPage events that bind route groups to MODX events.",
    inputSchema: {
      type: "object",
      properties: {
        id: { type: "number" },
        name: { type: "string" },
        active: { type: "number" },
        include_routes: { type: "boolean" },
        limit: { type: "number" },
        start: { type: "number" },
      },
    },
  },
  {
    name: "modx_virtualpage_get_event",
    description: "Read one VirtualPage event by id or name, including its routes.",
    inputSchema: {
      type: "object",
      properties: {
        id: { type: "number" },
        name: { type: "string" },
      },
    },
  },
  {
    name: "modx_virtualpage_create_event",
    description: "Create a VirtualPage event, usually OnPageNotFound or OnHandleRequest.",
    inputSchema: {
      type: "object",
      properties: {
        name: { type: "string" },
        description: { type: "string" },
        rank: { type: "number" },
        active: { type: "number" },
      },
      required: ["name"],
    },
  },
  {
    name: "modx_virtualpage_update_event",
    description: "Update a VirtualPage event by id or name.",
    inputSchema: {
      type: "object",
      properties: {
        id: { type: "number" },
        name: { type: "string" },
        description: { type: "string" },
        rank: { type: "number" },
        active: { type: "number" },
      },
    },
  },
  {
    name: "modx_virtualpage_list_handlers",
    description: "List VirtualPage handlers that render resources, snippets, chunks, or dynamic resources.",
    inputSchema: {
      type: "object",
      properties: {
        id: { type: "number" },
        name: { type: "string" },
        type: { type: "number" },
        entry: { type: "number" },
        active: { type: "number" },
        include_routes: { type: "boolean" },
        limit: { type: "number" },
        start: { type: "number" },
      },
    },
  },
  {
    name: "modx_virtualpage_get_handler",
    description: "Read one VirtualPage handler by id or name, including routes that use it.",
    inputSchema: {
      type: "object",
      properties: {
        id: { type: "number" },
        name: { type: "string" },
      },
    },
  },
  {
    name: "modx_virtualpage_create_handler",
    description:
      "Create a VirtualPage handler. type can be resource, snippet, chunk, dynamic_resource/template, or 0..3.",
    inputSchema: {
      type: "object",
      properties: {
        name: { type: "string" },
        type: { anyOf: [{ type: "string", enum: VIRTUALPAGE_HANDLER_TYPES }, { type: "number" }] },
        entry: { type: "number" },
        content: { type: "string" },
        description: { type: "string" },
        cache: { type: "number" },
        rank: { type: "number" },
        active: { type: "number" },
      },
      required: ["name"],
    },
  },
  {
    name: "modx_virtualpage_update_handler",
    description: "Update a VirtualPage handler by id or name.",
    inputSchema: {
      type: "object",
      properties: {
        id: { type: "number" },
        name: { type: "string" },
        type: { anyOf: [{ type: "string", enum: VIRTUALPAGE_HANDLER_TYPES }, { type: "number" }] },
        entry: { type: "number" },
        content: { type: "string" },
        description: { type: "string" },
        cache: { type: "number" },
        rank: { type: "number" },
        active: { type: "number" },
      },
    },
  },
  {
    name: "modx_virtualpage_list_routes",
    description: "List VirtualPage routes with their event and handler names.",
    inputSchema: {
      type: "object",
      properties: {
        id: { type: "number" },
        route: { type: "string" },
        method: { type: "string" },
        event: { type: "number" },
        event_name: { type: "string" },
        handler: { type: "number" },
        handler_name: { type: "string" },
        active: { type: "number" },
        limit: { type: "number" },
        start: { type: "number" },
      },
    },
  },
  {
    name: "modx_virtualpage_get_route",
    description: "Read one VirtualPage route by id, or by route plus optional method.",
    inputSchema: {
      type: "object",
      properties: {
        id: { type: "number" },
        route: { type: "string" },
        method: { type: "string" },
      },
    },
  },
  {
    name: "modx_virtualpage_create_route",
    description: "Create a VirtualPage route and bind its event plugin if needed.",
    inputSchema: {
      type: "object",
      properties: {
        route: { type: "string" },
        method: { type: "string" },
        handler: { type: "number" },
        handler_name: { type: "string" },
        event: { type: "number" },
        event_name: { type: "string" },
        description: { type: "string" },
        properties: { type: "object" },
        rank: { type: "number" },
        active: { type: "number" },
      },
      required: ["route", "method"],
    },
  },
  {
    name: "modx_virtualpage_update_route",
    description: "Update a VirtualPage route by id.",
    inputSchema: {
      type: "object",
      properties: {
        id: { type: "number" },
        route: { type: "string" },
        method: { type: "string" },
        handler: { type: "number" },
        handler_name: { type: "string" },
        event: { type: "number" },
        event_name: { type: "string" },
        description: { type: "string" },
        properties: { type: "object" },
        rank: { type: "number" },
        active: { type: "number" },
      },
      required: ["id"],
    },
  },
  {
    name: "modx_virtualpage_resolve_route",
    description: "Simulate VirtualPage route matching and return handler plus vp.* placeholders.",
    inputSchema: {
      type: "object",
      properties: {
        path: { type: "string" },
        uri: { type: "string" },
        method: { type: "string" },
      },
    },
  },
  {
    name: "modx_virtualpage_clear_cache",
    description: "Clear VirtualPage route/cache files and refresh MODX cache.",
    inputSchema: {
      type: "object",
      properties: {},
    },
  },
  {
    name: "modx_search_code",
    description:
      "Full-text search across element and resource CONTENT (and names). Finds the chunk/snippet/template/plugin/TV/resource that contains a string, including where an element is referenced or mentioned. Matches static elements by reading their static file. Use this to navigate the codebase (e.g. query 'header' finds the header chunk and everything that uses or mentions it).",
    inputSchema: {
      type: "object",
      properties: {
        query: { type: "string", description: "Substring to search for." },
        types: {
          type: "array",
          items: { type: "string", enum: ["chunk", "snippet", "template", "plugin", "tv", "resource"] },
          description: "Element/resource types to search. Default: all.",
        },
        limit: { type: "number", description: "Max results (default 50, max 200)." },
        case_sensitive: { type: "boolean", description: "Case-sensitive match (default false)." },
      },
      required: ["query"],
    },
  },
  {
    name: "modx_find_usages",
    description:
      "Find where an element is used: content matches for its name across all code, plus — if it is a template — the resources assigned to that template. Use before renaming/deleting an element.",
    inputSchema: {
      type: "object",
      properties: {
        name: { type: "string", description: "Element name (chunk/snippet/template/TV name)." },
        limit: { type: "number", description: "Max results (default 100)." },
      },
      required: ["name"],
    },
  },
  {
    name: "modx_list_resources",
    description:
      "List resources (the content tree), optionally filtered by parent, context, or a pagetitle/alias/uri query. Returns id, pagetitle, alias, uri, parent, template, published, isfolder, class_key, context_key.",
    inputSchema: {
      type: "object",
      properties: {
        parent: { type: "number", description: "Parent resource id (e.g. 0 for top level)." },
        context: { type: "string", description: "Context key (e.g. 'web')." },
        query: { type: "string", description: "Filter by pagetitle/alias/uri substring." },
        limit: { type: "number", description: "Max results (default 100, max 500)." },
        start: { type: "number", description: "Offset for pagination." },
      },
    },
  },

  {
    name: "modx_list_tv_input_types",
    description:
      "List the TV input (widget) types available on this site: core types plus any custom ones registered by other components (e.g. MIGX adds 'migx'/'migxdb'). Use the returned keys as 'field_type' when creating a TV.",
    inputSchema: { type: "object", properties: {} },
  },
  {
    name: "modx_check_integrations",
    description:
      "Report which popular MODX add-ons (miniShop2, MIGX, pdoTools, Tickets, etc.) are installed on this site and what modxMCP can do with each. Read-only.",
    inputSchema: { type: "object", properties: {} },
  },
  {
    name: "modx_install_package",
    description:
      "Install a transport package from a provider (default modx.com) by name, e.g. {package:'MIGX'}. Requires the modxmcp.allow_package_install setting to be enabled. Returns 'already_installed' if present. Use modx_check_integrations first to see what's missing.",
    inputSchema: {
      type: "object",
      properties: {
        package: { type: "string", description: "Package name as listed on the provider (e.g. 'MIGX', 'miniShop2')." },
        provider: { type: "number", description: "Optional transport provider id (defaults to modx.com)." },
      },
      required: ["package"],
    },
  },
  {
    name: "modx_regenerate_token",
    description:
      "Rotate the modxmcp.api_token to a fresh random value and return it. NOTE: this invalidates the current token — update MODX_MCP_TOKEN in your client config immediately, or the next call will be unauthorized.",
    inputSchema: { type: "object", properties: {} },
  },

  // ===================== miniShop2 product links =====================
  {
    name: "modx_ms2_list_link_types",
    description: "List miniShop2 link types (msLink): id, type, name, description.",
    inputSchema: { type: "object", properties: { query: { type: "string" }, limit: { type: "number" }, start: { type: "number" } } },
  },
  {
    name: "modx_ms2_get_link_type",
    description: "Get a miniShop2 link type by id.",
    inputSchema: { type: "object", properties: { id: { type: "number" } }, required: ["id"] },
  },
  {
    name: "modx_ms2_create_link_type",
    description: "Create a miniShop2 link type. 'type' is the relation kind that decides how products get linked.",
    inputSchema: {
      type: "object",
      properties: {
        name: { type: "string", description: "Display name (must be unique)." },
        type: { type: "string", enum: ["many_to_many", "one_to_many", "many_to_one", "one_to_one"], description: "Relation kind." },
        description: { type: "string" },
      },
      required: ["name", "type"],
    },
  },
  {
    name: "modx_ms2_update_link_type",
    description: "Update a miniShop2 link type.",
    inputSchema: {
      type: "object",
      properties: {
        id: { type: "number" },
        name: { type: "string" },
        type: { type: "string", enum: ["many_to_many", "one_to_many", "many_to_one", "one_to_one"] },
        description: { type: "string" },
      },
      required: ["id"],
    },
  },
  {
    name: "modx_ms2_delete_link_type",
    description: "Delete a miniShop2 link type by id.",
    inputSchema: { type: "object", properties: { id: { type: "number" } }, required: ["id"] },
  },
  {
    name: "modx_ms2_list_product_links",
    description: "List product-to-product links (msProductLink), optionally for one product. Returns link type + master/slave with pagetitles.",
    inputSchema: { type: "object", properties: { master: { type: "number", description: "Filter to links involving this product id." }, query: { type: "string" }, limit: { type: "number" }, start: { type: "number" } } },
  },
  {
    name: "modx_ms2_create_product_link",
    description: "Link two products under a link type. The link type's relation kind decides whether the reverse link is also created.",
    inputSchema: {
      type: "object",
      properties: {
        link: { type: "number", description: "Link type id (msLink)." },
        master: { type: "number", description: "Master product id." },
        slave: { type: "number", description: "Slave product id." },
      },
      required: ["link", "master", "slave"],
    },
  },
  {
    name: "modx_ms2_delete_product_link",
    description: "Remove a product link (by link type + master + slave).",
    inputSchema: {
      type: "object",
      properties: {
        link: { type: "number", description: "Link type id." },
        master: { type: "number", description: "Master product id." },
        slave: { type: "number", description: "Slave product id." },
      },
      required: ["link", "master", "slave"],
    },
  },

  // ===================== miniShop2 categories & orders =====================
  {
    name: "modx_ms2_list_categories",
    description: "List miniShop2 categories (msCategory).",
    inputSchema: { type: "object", properties: { parent: { type: "number", description: "Parent category id." }, query: { type: "string" }, limit: { type: "number" }, start: { type: "number" } } },
  },
  {
    name: "modx_ms2_create_category",
    description: "Create a miniShop2 category (msCategory resource).",
    inputSchema: { type: "object", properties: { pagetitle: { type: "string" }, parent: { type: "number", description: "Parent resource/category id." }, published: { type: "number" }, alias: { type: "string" } }, required: ["pagetitle", "parent"] },
  },
  {
    name: "modx_ms2_update_category",
    description: "Update a miniShop2 category.",
    inputSchema: { type: "object", properties: { id: { type: "number" }, pagetitle: { type: "string" }, parent: { type: "number" }, published: { type: "number" }, alias: { type: "string" } }, required: ["id"] },
  },
  {
    name: "modx_ms2_list_orders",
    description: "List miniShop2 orders (msOrder), filterable by status/customer/context/date range.",
    inputSchema: { type: "object", properties: { status: { type: "number", description: "Order status id." }, customer: { type: "number" }, context: { type: "string" }, query: { type: "string" }, date_start: { type: "string" }, date_end: { type: "string" }, limit: { type: "number" }, start: { type: "number" } } },
  },
  {
    name: "modx_ms2_get_order",
    description: "Get a miniShop2 order by id (with items/customer).",
    inputSchema: { type: "object", properties: { id: { type: "number" } }, required: ["id"] },
  },
  {
    name: "modx_ms2_update_order",
    description: "Update a miniShop2 order — typically to change its status (triggers ms2 status-change logic), delivery or payment.",
    inputSchema: { type: "object", properties: { id: { type: "number" }, status: { type: "number", description: "New order status id." }, delivery: { type: "number" }, payment: { type: "number" } }, required: ["id"] },
  },

  // ===================== MIGX configs =====================
  {
    name: "modx_migx_list_configs",
    description: "List MIGX configurations (migxConfig): id, name, category, published.",
    inputSchema: { type: "object", properties: { query: { type: "string", description: "Filter by name/category." }, category: { type: "string" }, limit: { type: "number" }, start: { type: "number" } } },
  },
  {
    name: "modx_migx_get_config",
    description: "Get a full MIGX configuration by id (formtabs, columns, buttons, filters, permissions — as stored JSON strings).",
    inputSchema: { type: "object", properties: { id: { type: "number" } }, required: ["id"] },
  },
  {
    name: "modx_migx_create_config",
    description: "Create a MIGX configuration. formtabs/columns/contextmenus/actionbuttons/columnbuttons/filters are JSON strings (same format MIGX stores in the manager).",
    inputSchema: {
      type: "object",
      properties: {
        name: { type: "string" },
        category: { type: "string" },
        formtabs: { type: "string", description: "JSON: tab/field definitions." },
        columns: { type: "string", description: "JSON: grid column definitions." },
        contextmenus: { type: "string" },
        actionbuttons: { type: "string" },
        columnbuttons: { type: "string" },
        filters: { type: "string" },
        extended: { type: "string" },
        permissions: { type: "string" },
        fieldpermissions: { type: "string" },
        published: { type: "number", description: "0 or 1." },
      },
      required: ["name"],
    },
  },
  {
    name: "modx_migx_update_config",
    description: "Update a MIGX configuration. Only the fields you pass are changed.",
    inputSchema: {
      type: "object",
      properties: {
        id: { type: "number" },
        name: { type: "string" },
        category: { type: "string" },
        formtabs: { type: "string" },
        columns: { type: "string" },
        contextmenus: { type: "string" },
        actionbuttons: { type: "string" },
        columnbuttons: { type: "string" },
        filters: { type: "string" },
        extended: { type: "string" },
        permissions: { type: "string" },
        fieldpermissions: { type: "string" },
        published: { type: "number" },
      },
      required: ["id"],
    },
  },
  {
    name: "modx_migx_delete_config",
    description: "Delete a MIGX configuration by id.",
    inputSchema: { type: "object", properties: { id: { type: "number" } }, required: ["id"] },
  },

  // ===================== Element property sets =====================
  {
    name: "modx_list_property_sets",
    description: "List property sets (modPropertySet): id, name, description, category.",
    inputSchema: { type: "object", properties: { query: { type: "string" }, limit: { type: "number" }, start: { type: "number" } } },
  },
  {
    name: "modx_get_property_set",
    description: "Get a property set by id (incl. its properties).",
    inputSchema: { type: "object", properties: { id: { type: "number" } }, required: ["id"] },
  },
  {
    name: "modx_create_property_set",
    description: "Create a property set. 'properties' is a JSON object of property definitions.",
    inputSchema: { type: "object", properties: { name: { type: "string" }, description: { type: "string" }, category: { type: "number" }, properties: { type: "object" } }, required: ["name"] },
  },
  {
    name: "modx_update_property_set",
    description: "Update a property set.",
    inputSchema: { type: "object", properties: { id: { type: "number" }, name: { type: "string" }, description: { type: "string" }, category: { type: "number" }, properties: { type: "object" } }, required: ["id"] },
  },
  {
    name: "modx_delete_property_set",
    description: "Delete a property set by id.",
    inputSchema: { type: "object", properties: { id: { type: "number" } }, required: ["id"] },
  },
  {
    name: "modx_assign_property_set",
    description: "Attach a property set to an element. Identify the element with its id + either element_class (e.g. 'modSnippet') or element_type ('snippet'/'chunk'/'template'/'plugin'/'tv').",
    inputSchema: { type: "object", properties: { element: { type: "number", description: "Element id." }, property_set: { type: "number", description: "Property set id." }, element_class: { type: "string" }, element_type: { type: "string", enum: ["snippet", "chunk", "template", "plugin", "tv"] } }, required: ["element", "property_set"] },
  },
  {
    name: "modx_unassign_property_set",
    description: "Detach a property set from an element (same identification as assign).",
    inputSchema: { type: "object", properties: { element: { type: "number" }, property_set: { type: "number" }, element_class: { type: "string" }, element_type: { type: "string", enum: ["snippet", "chunk", "template", "plugin", "tv"] } }, required: ["element", "property_set"] },
  },

  // ===================== Ops / introspection =====================
  {
    name: "modx_list_actions",
    description: "List the action names this modxMCP server build supports, grouped by area. Use it to detect client/server version skew.",
    inputSchema: { type: "object", properties: {} },
  },
  {
    name: "modx_clear_cache",
    description: "Refresh the MODX cache. Pass partitions (e.g. ['resource','context_settings']) to refresh only those; omit for a full refresh.",
    inputSchema: { type: "object", properties: { partitions: { type: "array", items: { type: "string" } } } },
  },
  {
    name: "modx_read_audit_log",
    description: "Read the modxMCP write-audit trail (newest last). Optionally filter to one action.",
    inputSchema: { type: "object", properties: { action: { type: "string", description: "Filter to this action name." }, limit: { type: "number", description: "Max entries (default 100)." } } },
  },
  {
    name: "modx_run_processor",
    description: "Run ANY MODX processor directly (escape hatch). Requires the modxmcp.allow_run_processor setting to be enabled. High privilege — prefer a dedicated tool when one exists.",
    inputSchema: {
      type: "object",
      properties: {
        processor: { type: "string", description: "Processor path, e.g. 'security/user/getlist' (relative to processors_path)." },
        properties: { type: "object", description: "Processor properties/params." },
        processors_path: { type: "string", description: "Override processors base path (for component processors)." },
      },
      required: ["processor"],
    },
  },

  // ===================== Users (modUser) =====================
  {
    name: "modx_list_users",
    description: "List manager/web users (modUser).",
    inputSchema: { type: "object", properties: { query: { type: "string" }, limit: { type: "number" }, start: { type: "number" } } },
  },
  {
    name: "modx_get_user",
    description: "Get a user by id.",
    inputSchema: { type: "object", properties: { id: { type: "number" } }, required: ["id"] },
  },
  {
    name: "modx_create_user",
    description: "Create a user. Pass 'password' to set it explicitly; omit to auto-generate. Profile fields (email, fullname, phone…) are accepted alongside.",
    inputSchema: { type: "object", properties: { username: { type: "string" }, password: { type: "string" }, email: { type: "string" }, fullname: { type: "string" }, active: { type: "number", description: "1 or 0." } }, required: ["username"] },
  },
  {
    name: "modx_update_user",
    description: "Update a user (profile fields, active state).",
    inputSchema: { type: "object", properties: { id: { type: "number" }, email: { type: "string" }, fullname: { type: "string" }, active: { type: "number" } }, required: ["id"] },
  },
  {
    name: "modx_delete_user",
    description: "Delete a user by id.",
    inputSchema: { type: "object", properties: { id: { type: "number" } }, required: ["id"] },
  },

  // ===================== Access Control (ACL) =====================
  // User groups (modUserGroup)
  {
    name: "modx_list_user_groups",
    description: "List MODX user groups (id, name, parent, rank).",
    inputSchema: { type: "object", properties: { query: { type: "string", description: "Filter by name." }, limit: { type: "number" }, start: { type: "number" } } },
  },
  {
    name: "modx_get_user_group",
    description: "Get a single user group by id.",
    inputSchema: { type: "object", properties: { id: { type: "number" } }, required: ["id"] },
  },
  {
    name: "modx_create_user_group",
    description: "Create a user group.",
    inputSchema: { type: "object", properties: { name: { type: "string" }, parent: { type: "number", description: "Parent user group id (0 = none)." } }, required: ["name"] },
  },
  {
    name: "modx_update_user_group",
    description: "Update a user group.",
    inputSchema: { type: "object", properties: { id: { type: "number" }, name: { type: "string" }, parent: { type: "number" } }, required: ["id"] },
  },
  {
    name: "modx_delete_user_group",
    description: "Delete a user group by id.",
    inputSchema: { type: "object", properties: { id: { type: "number" } }, required: ["id"] },
  },

  // User group membership (modUserGroupMember)
  {
    name: "modx_list_user_group_members",
    description: "List members of a user group.",
    inputSchema: { type: "object", properties: { usergroup: { type: "number", description: "User group id." }, limit: { type: "number" }, start: { type: "number" } }, required: ["usergroup"] },
  },
  {
    name: "modx_add_user_to_group",
    description: "Add a user to a user group, optionally with a role.",
    inputSchema: { type: "object", properties: { user: { type: "number", description: "User id." }, usergroup: { type: "number", description: "User group id." }, role: { type: "number", description: "Role id (optional)." } }, required: ["user", "usergroup"] },
  },
  {
    name: "modx_update_group_member",
    description: "Update a user's membership in a group (e.g. change role).",
    inputSchema: { type: "object", properties: { user: { type: "number" }, usergroup: { type: "number" }, role: { type: "number" } }, required: ["user", "usergroup"] },
  },
  {
    name: "modx_remove_user_from_group",
    description: "Remove a user from a user group.",
    inputSchema: { type: "object", properties: { user: { type: "number", description: "User id." }, usergroup: { type: "number", description: "User group id." } }, required: ["user", "usergroup"] },
  },

  // Roles (modUserGroupRole)
  {
    name: "modx_list_roles",
    description: "List access roles (id, name, authority).",
    inputSchema: { type: "object", properties: { query: { type: "string" }, limit: { type: "number" }, start: { type: "number" } } },
  },
  {
    name: "modx_get_role",
    description: "Get a role by id.",
    inputSchema: { type: "object", properties: { id: { type: "number" } }, required: ["id"] },
  },
  {
    name: "modx_create_role",
    description: "Create a role.",
    inputSchema: { type: "object", properties: { name: { type: "string" }, authority: { type: "number", description: "Authority level (lower = more authority)." } }, required: ["name"] },
  },
  {
    name: "modx_update_role",
    description: "Update a role.",
    inputSchema: { type: "object", properties: { id: { type: "number" }, name: { type: "string" }, authority: { type: "number" } }, required: ["id"] },
  },
  {
    name: "modx_delete_role",
    description: "Delete a role by id.",
    inputSchema: { type: "object", properties: { id: { type: "number" } }, required: ["id"] },
  },

  // Access policies (modAccessPolicy) + templates
  {
    name: "modx_list_access_policies",
    description: "List access policies (id, name, description, template).",
    inputSchema: { type: "object", properties: { query: { type: "string" }, limit: { type: "number" }, start: { type: "number" } } },
  },
  {
    name: "modx_create_access_policy",
    description: "Create an access policy.",
    inputSchema: { type: "object", properties: { name: { type: "string" }, description: { type: "string" }, template: { type: "number", description: "Policy template id." }, data: { type: "string", description: "JSON map of permission=>bool (optional)." } }, required: ["name", "template"] },
  },
  {
    name: "modx_update_access_policy",
    description: "Update an access policy (e.g. its permissions map).",
    inputSchema: { type: "object", properties: { id: { type: "number" }, name: { type: "string" }, description: { type: "string" }, permissions: { type: "string", description: "JSON map of permission=>bool." } }, required: ["id"] },
  },
  {
    name: "modx_delete_access_policy",
    description: "Delete an access policy by id.",
    inputSchema: { type: "object", properties: { id: { type: "number" } }, required: ["id"] },
  },
  {
    name: "modx_list_access_policy_templates",
    description: "List access policy templates.",
    inputSchema: { type: "object", properties: { query: { type: "string" }, limit: { type: "number" }, start: { type: "number" } } },
  },
  {
    name: "modx_create_access_policy_template",
    description: "Create an access policy template.",
    inputSchema: { type: "object", properties: { name: { type: "string" }, description: { type: "string" }, template_group: { type: "number" } }, required: ["name"] },
  },
  {
    name: "modx_update_access_policy_template",
    description: "Update an access policy template.",
    inputSchema: { type: "object", properties: { id: { type: "number" }, name: { type: "string" }, description: { type: "string" } }, required: ["id"] },
  },
  {
    name: "modx_delete_access_policy_template",
    description: "Delete an access policy template by id.",
    inputSchema: { type: "object", properties: { id: { type: "number" } }, required: ["id"] },
  },

  // Permissions catalogue (read-only)
  {
    name: "modx_list_access_permissions",
    description: "List permissions defined by a policy template (read-only catalogue).",
    inputSchema: { type: "object", properties: { template: { type: "number", description: "Policy template id." }, query: { type: "string" }, limit: { type: "number" }, start: { type: "number" } } },
  },

  // Resource groups (modResourceGroup)
  {
    name: "modx_list_resource_groups",
    description: "List resource groups (id, name).",
    inputSchema: { type: "object", properties: { query: { type: "string" }, limit: { type: "number" }, start: { type: "number" } } },
  },
  {
    name: "modx_create_resource_group",
    description: "Create a resource group.",
    inputSchema: { type: "object", properties: { name: { type: "string" } }, required: ["name"] },
  },
  {
    name: "modx_update_resource_group",
    description: "Update a resource group.",
    inputSchema: { type: "object", properties: { id: { type: "number" }, name: { type: "string" } }, required: ["id"] },
  },
  {
    name: "modx_delete_resource_group",
    description: "Delete a resource group by id.",
    inputSchema: { type: "object", properties: { id: { type: "number" } }, required: ["id"] },
  },
  {
    name: "modx_assign_resource_to_group",
    description: "Add a resource (document) to a resource group.",
    inputSchema: { type: "object", properties: { resource: { type: "number", description: "Resource id." }, resourceGroup: { type: "number", description: "Resource group id." } }, required: ["resource", "resourceGroup"] },
  },
  {
    name: "modx_remove_resource_from_group",
    description: "Remove a resource (document) from a resource group.",
    inputSchema: { type: "object", properties: { resource: { type: "number", description: "Resource id." }, resourceGroup: { type: "number", description: "Resource group id." } }, required: ["resource", "resourceGroup"] },
  },

  // Context access for a user group (modAccessContext)
  {
    name: "modx_list_context_access",
    description: "List context-access ACL entries, optionally filtered by usergroup/context/policy.",
    inputSchema: { type: "object", properties: { usergroup: { type: "number" }, context: { type: "string" }, policy: { type: "number" }, limit: { type: "number" }, start: { type: "number" } } },
  },
  {
    name: "modx_grant_context_access",
    description: "Grant a user group access to a context with a policy and minimum role authority.",
    inputSchema: { type: "object", properties: { principal: { type: "number", description: "User group id." }, target: { type: "string", description: "Context key (e.g. 'web', 'mgr')." }, policy: { type: "number", description: "Access policy id." }, authority: { type: "number", description: "Minimum role authority (0 = all)." } }, required: ["principal", "target", "policy"] },
  },
  {
    name: "modx_update_context_access",
    description: "Update a context-access ACL entry by id.",
    inputSchema: { type: "object", properties: { id: { type: "number" }, principal: { type: "number" }, target: { type: "string" }, policy: { type: "number" }, authority: { type: "number" } }, required: ["id"] },
  },
  {
    name: "modx_revoke_context_access",
    description: "Remove a context-access ACL entry by id.",
    inputSchema: { type: "object", properties: { id: { type: "number" } }, required: ["id"] },
  },

  // Resource-group access for a user group (modAccessResourceGroup)
  {
    name: "modx_list_resourcegroup_access",
    description: "List resource-group access ACL entries.",
    inputSchema: { type: "object", properties: { usergroup: { type: "number" }, limit: { type: "number" }, start: { type: "number" } } },
  },
  {
    name: "modx_grant_resourcegroup_access",
    description: "Grant a user group access to a resource group with a policy and minimum role authority.",
    inputSchema: { type: "object", properties: { principal: { type: "number", description: "User group id." }, target: { type: "number", description: "Resource group id." }, policy: { type: "number", description: "Access policy id." }, authority: { type: "number", description: "Minimum role authority (0 = all)." } }, required: ["principal", "target", "policy"] },
  },
  {
    name: "modx_update_resourcegroup_access",
    description: "Update a resource-group access ACL entry by id.",
    inputSchema: { type: "object", properties: { id: { type: "number" }, principal: { type: "number" }, target: { type: "number" }, policy: { type: "number" }, authority: { type: "number" } }, required: ["id"] },
  },
  {
    name: "modx_revoke_resourcegroup_access",
    description: "Remove a resource-group access ACL entry by id.",
    inputSchema: { type: "object", properties: { id: { type: "number" } }, required: ["id"] },
  },
];

server.setRequestHandler(ListToolsRequestSchema, async () => {
  return { tools: toolDefinitions };
});

function formatCodeFromResult(resultData) {
  return resultData.snippet || resultData.plugincode || resultData.content || "";
}

server.setRequestHandler(CallToolRequestSchema, async (request) => {
  const { name, arguments: args } = request.params;

  try {
    if (name === "modx_list_elements") {
      const result = await modxApiRequest({
        action: "list_elements",
        type: args.type,
      });
      return {
        content: [{ type: "text", text: JSON.stringify(result.data, null, 2) }],
      };
    }

    if (name === "modx_get_element") {
      const result = await modxApiRequest({
        action: "get_element",
        type: args.type,
        data: args,
      });
      return {
        content: [
          {
            type: "text",
            text: `=== PARAMETERS ===\n${JSON.stringify(
              result.data,
              null,
              2,
            )}\n\n=== CODE ===\n${formatCodeFromResult(result.data)}`,
          },
        ],
      };
    }

    if (
      name === "modx_update_element" ||
      name === "modx_create_element" ||
      name === "modx_delete_element"
    ) {
      const action = name.replace("modx_", "");
      const result = await modxApiRequest({
        action,
        type: args.type,
        data: args,
      });
      return {
        content: [{ type: "text", text: JSON.stringify(result.data, null, 2) }],
      };
    }

    if (name.startsWith("modx_")) {
      const action = name.replace("modx_", "");
      const result = await modxApiRequest({
        action,
        data: args,
      });
      return {
        content: [{ type: "text", text: JSON.stringify(result.data, null, 2) }],
      };
    }

    throw new Error(`Tool ${name} not found`);
  } catch (error) {
    return {
      content: [{ type: "text", text: `Error: ${error.message}` }],
      isError: true,
    };
  }
});

async function main() {
  const transport = new StdioServerTransport();
  await server.connect(transport);
  console.error("MODX MCP Server started successfully.");
}

main().catch(console.error);
