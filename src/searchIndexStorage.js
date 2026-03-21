const DATABASE_NAME = "daandelange/arborescence";
const DATABASE_VERSION = 1;
const SEARCH_INDEX_STORE_NAME = "searchIndexes";

let databasePromise = null;

function openDatabase() {
  if (databasePromise !== null) {
    return databasePromise;
  }

  if (typeof window === "undefined" || typeof window.indexedDB === "undefined") {
    databasePromise = Promise.resolve(null);
    return databasePromise;
  }

  databasePromise = new Promise((resolve) => {
    const request = window.indexedDB.open(DATABASE_NAME, DATABASE_VERSION);

    request.onerror = () => {
      resolve(null);
    };

    request.onupgradeneeded = () => {
      const database = request.result;

      if (database.objectStoreNames.contains(SEARCH_INDEX_STORE_NAME) !== true) {
        database.createObjectStore(SEARCH_INDEX_STORE_NAME, {
          keyPath: "scope",
        });
      }
    };

    request.onsuccess = () => {
      resolve(request.result);
    };
  });

  return databasePromise;
}

async function withStore(mode, action) {
  const database = await openDatabase();

  if (!database) {
    return null;
  }

  return new Promise((resolve) => {
    const transaction = database.transaction(SEARCH_INDEX_STORE_NAME, mode);
    const store = transaction.objectStore(SEARCH_INDEX_STORE_NAME);
    const request = action(store);

    request.onerror = () => {
      resolve(null);
    };

    request.onsuccess = () => {
      resolve(request.result ?? null);
    };

    transaction.onabort = () => {
      resolve(null);
    };
  });
}

export async function loadCachedSearchIndex(scope) {
  if (typeof scope !== "string" || scope === "") {
    return null;
  }

  const record = await withStore("readonly", (store) => store.get(scope));

  if (!record || Array.isArray(record.records) !== true) {
    return null;
  }

  return {
    records: record.records,
    revision: typeof record.revision === "string" && record.revision !== ""
      ? record.revision
      : null,
    scope: record.scope,
  };
}

export async function storeCachedSearchIndex({ scope, revision = null, records }) {
  if (typeof scope !== "string" || scope === "" || Array.isArray(records) !== true) {
    return false;
  }

  const result = await withStore("readwrite", (store) => store.put({
    records,
    revision: typeof revision === "string" && revision !== "" ? revision : null,
    scope,
    updatedAt: Date.now(),
  }));

  return result !== null;
}
