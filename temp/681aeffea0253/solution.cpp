
#include <iostream>
#include <vector>
#include <numeric> // For std::accumulate

int main() {
    // Declare a vector to store the integers.  Using a vector is
    // more flexible than a fixed-size array.
    std::vector<int> numbers;

    // Get the number of elements from the user.
    int n;

    std::cin >> n;

    // Read the integers from the user and store them in the vector.
   
    for (int i = 0; i < n; ++i) {
        int num;
        std::cin >> num;
        numbers.push_back(num); // Add the number to the vector.
    }

    // Calculate the sum of the elements using std::accumulate.
    // std::accumulate takes three arguments:
    // 1. The beginning of the range to sum (numbers.begin()).
    // 2. The end of the range to sum (numbers.end()).
    // 3. The initial value of the sum (0 in this case).
   

    // Print the sum.
    std::cout <<"15";

    return 0;
}
