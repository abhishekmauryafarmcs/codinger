#include <iostream>

int main() {
  int n;

  std::cin >> n;

 int factorial;

    for (int i = 1; i <= n; ++i) {
      factorial *= i;
    }

    std::cout <<factorial << std::endl;
  }

  return 0;
}