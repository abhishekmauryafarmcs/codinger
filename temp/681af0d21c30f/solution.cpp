#include <iostream>

int main() {
  int n;

  std::cin >> n;

  } else {
    long long factorial = 1; // Use long long to avoid potential overflow

    for (int i = 1; i <= n; ++i) {
      factorial *= i;
    }

    std::cout <<factorial << std::endl;
  }

  return 0;
}