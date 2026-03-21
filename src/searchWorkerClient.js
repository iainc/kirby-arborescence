import SearchWorkerFactory from "./searchWorker.js?worker&inline";

function toError(error) {
  if (error instanceof Error) {
    return error;
  }

  return new Error("Arborescence search worker failed.");
}

export default class SearchWorkerClient {
  constructor() {
    this.failure = null;
    this.nextRequestId = 0;
    this.pendingRequests = new Map();
    this.worker = new SearchWorkerFactory();
    this.worker.addEventListener("message", (event) => this.onMessage(event));
    this.worker.addEventListener("error", (event) => this.onError(event?.error));
  }

  onError(error) {
    const failure = toError(error);
    this.failure = failure;

    for (const pending of this.pendingRequests.values()) {
      pending.reject(failure);
    }

    this.pendingRequests.clear();
  }

  onMessage(event) {
    const message = event?.data ?? {};
    const requestId = message.requestId;

    if (typeof requestId !== "number") {
      return;
    }

    const pending = this.pendingRequests.get(requestId);
    if (!pending) {
      return;
    }

    this.pendingRequests.delete(requestId);
    pending.resolve(message);
  }

  request(type, payload = {}) {
    if (this.failure) {
      return Promise.reject(this.failure);
    }

    const requestId = this.nextRequestId + 1;
    this.nextRequestId = requestId;

    return new Promise((resolve, reject) => {
      this.pendingRequests.set(requestId, {
        reject,
        resolve,
      });

      this.worker.postMessage({
        ...payload,
        requestId,
        type,
      });
    });
  }

  async search(query) {
    const response = await this.request("run-search", { query });
    return Array.isArray(response.items) === true ? response.items : [];
  }

  async setSearchIndex(records) {
    await this.request("set-search-index", { records });
  }

  terminate() {
    this.worker?.terminate();
    this.worker = null;
    this.onError(new Error("Arborescence search worker terminated."));
  }
}
