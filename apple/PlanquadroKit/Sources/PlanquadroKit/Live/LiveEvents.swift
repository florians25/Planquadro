import Foundation

/// Nutzlast eines Ereignisses aus `GET events` (nur betroffene Zellen, nie Inhalte).
public struct LiveEvent: Codable, Hashable, Sendable {
    public enum Kind: String, Codable, Sendable {
        case cellsChanged = "cells_changed"
        case published
        case requestChanged = "request_changed"
    }

    public struct Cell: Hashable, Sendable {
        public var userID: Int
        public var date: ISODay
    }

    public var type: Kind
    public var published: Bool
    /// `YYYY-MM`
    public var months: [String]
    /// `[user_id, Y-m-d]`; nil = ganzer Monat
    public var cells: [Cell]?
    /// betrifft eigene Zellen (nil = unbekannt)
    public var selfAffected: Bool?
    /// selbst ausgelöst
    public var own: Bool

    public init(type: Kind, published: Bool, months: [String], cells: [Cell]?, selfAffected: Bool?, own: Bool = false) {
        self.type = type
        self.published = published
        self.months = months
        self.cells = cells
        self.selfAffected = selfAffected
        self.own = own
    }

    enum CodingKeys: String, CodingKey {
        case type, published, months, cells, own
        case selfAffected = "self"
    }

    public init(from decoder: Decoder) throws {
        let c = try decoder.container(keyedBy: CodingKeys.self)
        type = try c.decode(Kind.self, forKey: .type)
        published = try c.decodeIfPresent(Bool.self, forKey: .published) ?? false
        months = try c.decodeIfPresent([String].self, forKey: .months) ?? []
        selfAffected = try c.decodeIfPresent(Bool.self, forKey: .selfAffected)
        own = try c.decodeIfPresent(Bool.self, forKey: .own) ?? false
        if c.contains(.cells), try !c.decodeNil(forKey: .cells) {
            var arr = try c.nestedUnkeyedContainer(forKey: .cells)
            var out: [Cell] = []
            while !arr.isAtEnd {
                var pair = try arr.nestedUnkeyedContainer()
                let uid = try pair.decode(Int.self)
                let date = try pair.decode(String.self)
                out.append(Cell(userID: uid, date: date))
            }
            cells = out
        } else {
            cells = nil
        }
    }

    public func encode(to encoder: Encoder) throws {
        var c = encoder.container(keyedBy: CodingKeys.self)
        try c.encode(type, forKey: .type)
        try c.encode(published, forKey: .published)
        try c.encode(months, forKey: .months)
        try c.encodeIfPresent(selfAffected, forKey: .selfAffected)
        try c.encode(own, forKey: .own)
        if let cells {
            var arr = c.nestedUnkeyedContainer(forKey: .cells)
            for cell in cells {
                var pair = arr.nestedUnkeyedContainer()
                try pair.encode(cell.userID)
                try pair.encode(cell.date)
            }
        } else {
            try c.encodeNil(forKey: .cells)
        }
    }
}

/// Ein Server-Sent Event.
public struct SSEMessage: Hashable, Sendable {
    public var event: String
    public var data: String
    public var id: String?

    public init(event: String = "message", data: String, id: String? = nil) {
        self.event = event
        self.data = data
        self.id = id
    }
}

/// Inkrementeller Parser für `text/event-stream` (WHATWG HTML, Abschnitt Server-Sent Events).
/// Kommentarzeilen (`: hb`) sind Heartbeats; Zeilen nur mit `id:` rücken den Cursor vor.
public struct SSEParser: Sendable {
    public private(set) var lastEventID: String?
    public private(set) var retry: Int?
    private var buffer = Data()
    private var eventType = ""
    private var dataLines: [String] = []
    private var sawCR = false

    public init(lastEventID: String? = nil) {
        self.lastEventID = lastEventID
    }

    /// Nimmt Bytes entgegen und liefert alle vollständig empfangenen Ereignisse.
    public mutating func feed(_ chunk: Data) -> [SSEMessage] {
        var out: [SSEMessage] = []
        for byte in chunk {
            if sawCR {
                sawCR = false
                if byte == 0x0A { continue } // CRLF
            }
            if byte == 0x0A || byte == 0x0D {
                if byte == 0x0D { sawCR = true }
                let line = String(decoding: buffer, as: UTF8.self)
                buffer.removeAll(keepingCapacity: true)
                if let m = processLine(line) { out.append(m) }
            } else {
                buffer.append(byte)
            }
        }
        return out
    }

    private mutating func processLine(_ raw: String) -> SSEMessage? {
        var line = raw
        if line.hasPrefix("\u{FEFF}") { line.removeFirst() }
        if line.isEmpty { return dispatch() }
        if line.hasPrefix(":") { return nil }
        let field: String
        var value: String
        if let idx = line.firstIndex(of: ":") {
            field = String(line[..<idx])
            value = String(line[line.index(after: idx)...])
            if value.hasPrefix(" ") { value.removeFirst() }
        } else {
            field = line
            value = ""
        }
        switch field {
        case "event": eventType = value
        case "data": dataLines.append(value)
        case "id": if !value.contains("\u{0}") { lastEventID = value }
        case "retry": if let n = Int(value), value.allSatisfy(\.isNumber) { retry = n }
        default: break
        }
        return nil
    }

    private mutating func dispatch() -> SSEMessage? {
        defer {
            eventType = ""
            dataLines = []
        }
        guard !dataLines.isEmpty else { return nil }
        return SSEMessage(event: eventType.isEmpty ? "message" : eventType, data: dataLines.joined(separator: "\n"), id: lastEventID)
    }
}
