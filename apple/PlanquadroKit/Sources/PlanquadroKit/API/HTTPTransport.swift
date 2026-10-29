import Foundation
#if canImport(FoundationNetworking)
import FoundationNetworking
#endif

public struct HTTPRequest: Sendable, Hashable {
    public var method: String
    public var url: URL
    public var headers: [String: String]
    public var body: Data?
    public var timeout: TimeInterval

    public init(method: String, url: URL, headers: [String: String] = [:], body: Data? = nil, timeout: TimeInterval = 20) {
        self.method = method
        self.url = url
        self.headers = headers
        self.body = body
        self.timeout = timeout
    }

    public func header(_ name: String) -> String? {
        headers.first { $0.key.caseInsensitiveCompare(name) == .orderedSame }?.value
    }
}

public struct HTTPResponse: Sendable, Hashable {
    public var status: Int
    /// Header-Namen in Kleinbuchstaben
    public var headers: [String: String]
    public var body: Data

    public init(status: Int, headers: [String: String] = [:], body: Data = Data()) {
        self.status = status
        self.headers = Dictionary(headers.map { ($0.key.lowercased(), $0.value) }, uniquingKeysWith: { a, _ in a })
        self.body = body
    }

    public func header(_ name: String) -> String? { headers[name.lowercased()] }
}

/// Einzelner HTTP-Aufruf (in Tests und im Demo-Modus ersetzbar).
public protocol HTTPTransport: Sendable {
    func send(_ request: HTTPRequest) async throws -> HTTPResponse
}

/// Teil eines gestreamten HTTP-Bodys (Server-Sent Events).
public enum StreamChunk: Sendable, Hashable {
    case response(status: Int, headers: [String: String])
    case data(Data)
}

/// Gestreamter Abruf (für `GET events`).
public protocol EventStreamTransport: Sendable {
    func stream(_ request: HTTPRequest) -> AsyncThrowingStream<StreamChunk, Error>
}

private func makeURLRequest(_ r: HTTPRequest) -> URLRequest {
    var req = URLRequest(url: r.url, cachePolicy: .reloadIgnoringLocalCacheData, timeoutInterval: r.timeout)
    req.httpMethod = r.method
    for (k, v) in r.headers { req.setValue(v, forHTTPHeaderField: k) }
    req.httpBody = r.body
    req.httpShouldHandleCookies = false
    return req
}

private func headerMap(_ resp: HTTPURLResponse) -> [String: String] {
    var out: [String: String] = [:]
    for (k, v) in resp.allHeaderFields {
        out[String(describing: k).lowercased()] = String(describing: v)
    }
    return out
}

/// URLSession-Transport: ephemeral, ohne Cookies und ohne URL-Cache.
public final class URLSessionTransport: HTTPTransport, EventStreamTransport, @unchecked Sendable {
    private let session: URLSession
    private let configuration: URLSessionConfiguration

    public init(configuration: URLSessionConfiguration? = nil) {
        let c = configuration ?? {
            let c = URLSessionConfiguration.ephemeral
            c.httpShouldSetCookies = false
            c.httpCookieAcceptPolicy = .never
            c.urlCache = nil
            c.requestCachePolicy = .reloadIgnoringLocalCacheData
            c.timeoutIntervalForRequest = 30
            return c
        }()
        self.configuration = c
        session = URLSession(configuration: c)
    }

    public func send(_ request: HTTPRequest) async throws -> HTTPResponse {
        let req = makeURLRequest(request)
        let box = TaskBox()
        return try await withTaskCancellationHandler {
            try await withCheckedThrowingContinuation { (cont: CheckedContinuation<HTTPResponse, Error>) in
                let task = session.dataTask(with: req) { data, response, error in
                    if let error {
                        cont.resume(throwing: error)
                        return
                    }
                    guard let http = response as? HTTPURLResponse else {
                        cont.resume(throwing: URLError(.badServerResponse))
                        return
                    }
                    cont.resume(returning: HTTPResponse(status: http.statusCode, headers: headerMap(http), body: data ?? Data()))
                }
                box.set(task)
                task.resume()
            }
        } onCancel: {
            box.cancel()
        }
    }

    public func stream(_ request: HTTPRequest) -> AsyncThrowingStream<StreamChunk, Error> {
        var r = request
        // Ereignisstrom: lange Laufzeit, aber begrenzte Wartezeit zwischen Paketen (Heartbeats ~15 s)
        r.timeout = max(request.timeout, 60)
        let req = makeURLRequest(r)
        let config = configuration
        return AsyncThrowingStream { continuation in
            let delegate = StreamDelegate(continuation: continuation)
            let s = URLSession(configuration: config, delegate: delegate, delegateQueue: nil)
            let task = s.dataTask(with: req)
            continuation.onTermination = { _ in
                task.cancel()
                s.invalidateAndCancel()
            }
            task.resume()
        }
    }
}

/// Hält den laufenden Task für die Abbruchbehandlung.
private final class TaskBox: @unchecked Sendable {
    private let lock = NSLock()
    private var task: URLSessionTask?
    private var cancelled = false

    func set(_ t: URLSessionTask) {
        lock.lock()
        task = t
        let c = cancelled
        lock.unlock()
        if c { t.cancel() }
    }

    func cancel() {
        lock.lock()
        cancelled = true
        let t = task
        lock.unlock()
        t?.cancel()
    }
}

private final class StreamDelegate: NSObject, URLSessionDataDelegate, @unchecked Sendable {
    let continuation: AsyncThrowingStream<StreamChunk, Error>.Continuation

    init(continuation: AsyncThrowingStream<StreamChunk, Error>.Continuation) {
        self.continuation = continuation
    }

    func urlSession(_ session: URLSession, dataTask: URLSessionDataTask, didReceive response: URLResponse,
                    completionHandler: @escaping (URLSession.ResponseDisposition) -> Void) {
        if let http = response as? HTTPURLResponse {
            continuation.yield(.response(status: http.statusCode, headers: headerMap(http)))
        }
        completionHandler(.allow)
    }

    func urlSession(_ session: URLSession, dataTask: URLSessionDataTask, didReceive data: Data) {
        continuation.yield(.data(data))
    }

    func urlSession(_ session: URLSession, task: URLSessionTask, didCompleteWithError error: Error?) {
        if let error {
            continuation.finish(throwing: error)
        } else {
            continuation.finish()
        }
        session.finishTasksAndInvalidate()
    }
}
