#include <chrono>
#include <cstdlib>
#include <iostream>
#include <string>
#include <thread>
#ifdef _WIN32
#include <fcntl.h>
#include <io.h>
#endif
int main() {
#ifdef _WIN32
    _setmode(_fileno(stdin), _O_BINARY);
    _setmode(_fileno(stdout), _O_BINARY);
#endif
    unsigned char header[4];
    std::cin.read(reinterpret_cast<char *>(header), 4);
    unsigned size = unsigned(header[0]) << 24 | unsigned(header[1]) << 16 | unsigned(header[2]) << 8 | header[3];
    std::string input(size, '\0');
    std::cin.read(input.data(), size);
    const char *mode = std::getenv("TINYPDF_TEST_FAILURE");
    std::string failure = mode ? mode : "crash";
    if (failure == "crash") return 3;
    if (failure == "timeout") { std::this_thread::sleep_for(std::chrono::seconds(30)); return 0; }
    if (failure == "oversize") { std::cout.write("\177\377\377\377", 4); return 0; }
    if (failure == "truncated") { std::cout.write("\0\0\0\40{}", 6); return 0; }
    std::string output;
    if (failure == "protocol") output = R"({"protocol":2,"id":1,"result":{}})";
    else if (failure == "id") output = R"({"protocol":1,"id":2,"result":{}})";
    else if (failure == "capability") output = R"({"protocol":1,"id":1,"result":{"capabilities":[]}})";
    else output = "not JSON";
    size = output.size();
    unsigned char prefix[] = {static_cast<unsigned char>(size >> 24), static_cast<unsigned char>(size >> 16), static_cast<unsigned char>(size >> 8), static_cast<unsigned char>(size)};
    std::cout.write(reinterpret_cast<char *>(prefix), 4);
    std::cout << output << std::flush;
}
