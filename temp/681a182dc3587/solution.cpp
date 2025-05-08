#include <iostream>

long long factorial(int n) {
  if (n < 0) {
    return -1; // Indicate an error for negative input
  } else if (n == 0) {
    return 1;
  } else {
    long long result = 1;
    for (int i = 1; i <= n; ++i) {
      result *= i;
    }
    return result;
  }
}

int main() {
  int num;
  long long fact;

  std::cout << "Enter a non-negative integer: ";
  std::cin >> num;

  fact = factorial(num);

  if (fact != -1) {
    std::cout << fact << std::endl;
  } else {
    std::cout << "Factorial is not defined for negative numbers." << std::endl;
  }

  return 0;
}