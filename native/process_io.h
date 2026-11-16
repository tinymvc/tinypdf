#pragma once

#ifdef _WIN32
#include <winsock2.h>
#include <ws2tcpip.h>
#include <fcntl.h>
#include <io.h>
#else
#include <arpa/inet.h>
#include <sys/socket.h>
#include <netinet/tcp.h>
#include <unistd.h>
#endif
#include <algorithm>
#include <iostream>
#include <stdexcept>
#include <string>

// Windows PHP pipes cannot provide nonblocking reads. The same framed protocol
// can use an authenticated loopback connection without an intermediary process.
class ProcessIo {
#ifdef _WIN32
    SOCKET socket_ = INVALID_SOCKET;
    bool initialized_ = false;
    static constexpr SOCKET invalid_ = INVALID_SOCKET;
#else
    int socket_ = -1;
    static constexpr int invalid_ = -1;
#endif
public:
    ProcessIo(int argc, char **argv) {
#ifdef _WIN32
        _setmode(_fileno(stdin), _O_BINARY);
        _setmode(_fileno(stdout), _O_BINARY);
#endif
        if (argc == 1) return;
        if (argc != 4 || std::string(argv[1]) != "--connect" || std::string(argv[3]).size() != 32)
            throw std::runtime_error("Invalid transport arguments");
        std::string address = argv[2];
        if (address.rfind("127.0.0.1:", 0) != 0) throw std::runtime_error("Transport must use loopback");
        int port = std::stoi(address.substr(10));
        if (port < 1 || port > 65535) throw std::runtime_error("Invalid transport port");
#ifdef _WIN32
        WSADATA data;
        if (WSAStartup(MAKEWORD(2, 2), &data)) throw std::runtime_error("Cannot initialize local transport");
        initialized_ = true;
#endif
        socket_ = socket(AF_INET, SOCK_STREAM, IPPROTO_TCP);
        sockaddr_in target{};
        target.sin_family = AF_INET;
        target.sin_port = htons(static_cast<unsigned short>(port));
        target.sin_addr.s_addr = htonl(INADDR_LOOPBACK);
        if (socket_ == invalid_ || connect(socket_, reinterpret_cast<sockaddr *>(&target), sizeof(target)))
            throw std::runtime_error("Cannot connect local transport");
        int noDelay = 1;
        setsockopt(socket_, IPPROTO_TCP, TCP_NODELAY, reinterpret_cast<const char *>(&noDelay), sizeof(noDelay));
        if (!write(argv[3], 32)) throw std::runtime_error("Cannot authenticate local transport");
    }

    ~ProcessIo() {
#ifdef _WIN32
        if (socket_ != invalid_) closesocket(socket_);
        if (initialized_) WSACleanup();
#else
        if (socket_ != invalid_) close(socket_);
#endif
    }

    size_t read(char *data, size_t length) {
        if (socket_ == invalid_) {
            std::cin.read(data, length);
            return static_cast<size_t>(std::cin.gcount());
        }
        size_t offset = 0;
        while (offset < length) {
            int count = recv(socket_, data + offset, static_cast<int>(std::min(length - offset, size_t(65536))), 0);
            if (count <= 0) break;
            offset += count;
        }
        return offset;
    }

    bool write(const char *data, size_t length) {
        if (socket_ == invalid_) {
            std::cout.write(data, length);
            std::cout.flush();
            return bool(std::cout);
        }
        size_t offset = 0;
        while (offset < length) {
            int count = send(socket_, data + offset, static_cast<int>(std::min(length - offset, size_t(65536))), 0);
            if (count <= 0) return false;
            offset += count;
        }
        return true;
    }
};
